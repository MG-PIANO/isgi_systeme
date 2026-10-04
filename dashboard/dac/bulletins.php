<?php
// dashboard/dac/bulletins.php

// ============================================
// 1. INITIALISATION
// ============================================
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Vérifier que l'utilisateur est connecté et est un DAC (role_id = 5)
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// ============================================
// 2. CONFIGURATION BASE DE DONNÉES
// ============================================
$database_found = false;
$db = null;

// Chemins possibles pour le fichier database.php
$possible_paths = [
    ROOT_PATH . '/config/database.php',
    dirname(dirname(dirname(dirname(__FILE__)))) . '/config/database.php',
    '../../../../config/database.php',
    '../../../config/database.php',
    '../config/database.php',
    'config/database.php'
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $database_found = true;
        break;
    }
}

// Si le fichier n'est pas trouvé, créer une connexion directe
if (!$database_found) {
    try {
        $db = new PDO(
            'mysql:host=localhost;dbname=isgi_systeme;charset=utf8mb4',
            'root',
            'admin1234',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    } catch (PDOException $e) {
        die("<div style='padding:20px;background:#f8d7da;color:#721c24;border-radius:5px;'>
            <h3>Erreur de connexion à la base de données</h3>
            <p>" . htmlspecialchars($e->getMessage()) . "</p>
            <p>Vérifiez votre configuration dans config/database.php</p>
        </div>");
    }
} else {
    // Utiliser la classe Database si elle existe
    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
    }
}

// Inclure la bibliothèque QR Code
error_reporting(E_ALL & ~E_DEPRECATED);
require_once ROOT_PATH . '/libs/phpqrcode/qrlib.php';
error_reporting(E_ALL);

// ============================================
// 3. FONCTIONS UTILITAIRES
// ============================================
function safe_html($value, $default = '') {
    if ($value === null || $value === '') {
        return htmlspecialchars($default);
    }
    return htmlspecialchars($value);
}

function formatDateFr($date) {
    if (empty($date) || $date == '0000-00-00') return '';
    return date('d/m/Y', strtotime($date));
}

function getStatutBadge($statut) {
    $badges = [
        'actif' => 'success',
        'valide' => 'success', 
        'present' => 'success',
        'admis' => 'success',
        'en_attente' => 'warning',
        'en_cours' => 'warning',
        'planifie' => 'warning',
        'brouillon' => 'secondary',
        'annule' => 'danger',
        'rejete' => 'danger',
        'absent' => 'danger',
        'termine' => 'info',
        'validee' => 'info',
        'publie' => 'primary'
    ];
    
    $color = $badges[$statut] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . ucfirst($statut) . '</span>';
}

function getAnneesAcademiques($db) {
    try {
        $sql = "SELECT * FROM annees_academiques ORDER BY libelle DESC";
        $stmt = $db->query($sql);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getSemestres($db) {
    try {
        $sql = "SELECT * FROM semestres ORDER BY numero";
        $stmt = $db->query($sql);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getClasses($db) {
    try {
        $site_id = $_SESSION['site_id'] ?? 1;
        $sql = "SELECT c.*, f.nom as filiere_nom 
                FROM classes c 
                LEFT JOIN filieres f ON c.filiere_id = f.id 
                WHERE c.site_id = ?
                ORDER BY c.nom";
        $stmt = $db->prepare($sql);
        $stmt->execute([$site_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getEtudiantsParClasse($db, $classe_id) {
    try {
        $sql = "SELECT e.*, c.nom as classe_nom 
                FROM etudiants e 
                JOIN classes c ON e.classe_id = c.id 
                WHERE e.classe_id = ? 
                ORDER BY e.nom, e.prenom";
        $stmt = $db->prepare($sql);
        $stmt->execute([$classe_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function getMatieresParClasse($db, $classe_id) {
    try {
        // Récupérer d'abord les informations de la classe
        $sql_classe = "SELECT filiere_id, niveau_id, site_id FROM classes WHERE id = ?";
        $stmt_classe = $db->prepare($sql_classe);
        $stmt_classe->execute([$classe_id]);
        $classe = $stmt_classe->fetch();
        
        if (!$classe) {
            return [];
        }
        
        // Récupérer les matières qui ont les mêmes filiere_id, niveau_id et site_id
        $sql = "SELECT m.* 
                FROM matieres m 
                WHERE m.filiere_id = ? 
                AND m.niveau_id = ? 
                AND m.site_id = ?
                ORDER BY m.code, m.nom";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $classe['filiere_id'],
            $classe['niveau_id'],
            $classe['site_id']
        ]);
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Erreur getMatieresParClasse: " . $e->getMessage());
        return [];
    }
}

function getMention($moyenne) {
    if ($moyenne === null) return 'Non évalué';
    if ($moyenne >= 16) return 'Très Bien';
    if ($moyenne >= 14) return 'Bien';
    if ($moyenne >= 12) return 'Assez Bien';
    if ($moyenne >= 10) return 'Passable';
    return 'Échoué';
}

function getAppreciation($moyenne) {
    if ($moyenne === null) return 'Non évalué';
    if ($moyenne >= 16) return 'Excellente performance, félicitations !';
    if ($moyenne >= 14) return 'Très bon travail, continuez ainsi !';
    if ($moyenne >= 12) return 'Bon travail, des progrès encore possibles.';
    if ($moyenne >= 10) return 'Satisfaisant, besoin de plus d\'efforts.';
    return 'Insuffisant, nécessite un travail approfondi.';
}

// ============================================
// 4. FONCTIONS QR CODE
// ============================================
function generateQRCode($data, $filename) {
    $path = ROOT_PATH . '/uploads/qrcodes/bulletins/';
    
    // Créer le dossier s'il n'existe pas
    if (!file_exists($path)) {
        mkdir($path, 0777, true);
    }
    
    $filepath = $path . $filename;
    
    // Générer le QR code
    try {
        QRcode::png($data, $filepath, QR_ECLEVEL_H, 10, 2);
        
        // Vérifier si le fichier a été créé
        if (!file_exists($filepath)) {
            error_log("Le fichier QR code n'a pas été créé: " . $filepath);
            return null;
        }
        
        // Retourner le chemin web
        return '/uploads/qrcodes/bulletins/' . $filename;
    } catch (Exception $e) {
        error_log("Erreur lors de la génération du QR code: " . $e->getMessage());
        return null;
    }
}

function genererQRCodeBulletin($db, $bulletin_id, $etudiant_id, $annee_id, $semestre_id) {
    try {
        // Récupérer les infos de l'étudiant
        $sql = "SELECT e.matricule, e.nom, e.prenom, c.nom as classe_nom, 
                       aa.libelle as annee_academique, s.numero as semestre_numero
                FROM etudiants e
                JOIN classes c ON e.classe_id = c.id
                JOIN annees_academiques aa ON aa.id = ?
                JOIN semestres s ON s.id = ?
                WHERE e.id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$annee_id, $semestre_id, $etudiant_id]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return ['error' => 'Données étudiant non trouvées'];
        }
        
        // Créer les données pour le QR code
        $qr_data = json_encode([
            'type' => 'bulletin',
            'id' => $bulletin_id,
            'matricule' => $data['matricule'],
            'nom' => $data['nom'],
            'prenom' => $data['prenom'],
            'classe' => $data['classe_nom'],
            'annee_academique' => $data['annee_academique'],
            'semestre' => $data['semestre_numero'],
            'date_emission' => date('Y-m-d H:i:s'),
            'site_id' => $_SESSION['site_id'] ?? 1,
            'hash' => hash('sha256', $bulletin_id . $data['matricule'] . date('Ymd'))
        ]);
        
        // Nom du fichier
        $filename = 'bulletin_' . $bulletin_id . '_' . date('Ymd_His') . '.png';
        
        // Générer le QR code
        $qr_url = generateQRCode($qr_data, $filename);
        
        if (!$qr_url) {
            return ['error' => 'Impossible de générer le fichier QR code'];
        }
        
        return [
            'qr_path' => ROOT_PATH . $qr_url,
            'qr_url' => $qr_url,
            'qr_data' => $qr_data
        ];
        
    } catch (Exception $e) {
        error_log("Erreur génération QR code: " . $e->getMessage());
        return ['error' => 'Erreur lors de la génération du QR code: ' . $e->getMessage()];
    }
}

// ============================================
// 5. FONCTIONS PRINCIPALES
// ============================================
function calculerMoyenneEtudiant($db, $etudiant_id, $semestre_id, $annee_id) {
    try {
        // Récupérer les informations de l'étudiant AVEC le nom de la classe
        $sql_etudiant = "SELECT e.*, c.id as classe_id, c.nom as classe_nom, 
                                  c.filiere_id, c.niveau_id, c.site_id
                        FROM etudiants e 
                        JOIN classes c ON e.classe_id = c.id 
                        WHERE e.id = ?";
        $stmt_etudiant = $db->prepare($sql_etudiant);
        $stmt_etudiant->execute([$etudiant_id]);
        $etudiant = $stmt_etudiant->fetch();
        
        if (!$etudiant) {
            error_log("Étudiant non trouvé: ID $etudiant_id");
            return null;
        }
        
        // Récupérer les matières de la classe (basées sur filiere_id, niveau_id, site_id)
        $sql_matieres = "SELECT m.* 
                        FROM matieres m 
                        WHERE m.filiere_id = ? 
                        AND m.niveau_id = ? 
                        AND m.site_id = ?
                        ORDER BY m.code, m.nom";
        
        $stmt_matieres = $db->prepare($sql_matieres);
        $stmt_matieres->execute([
            $etudiant['filiere_id'],
            $etudiant['niveau_id'],
            $etudiant['site_id']
        ]);
        $matieres = $stmt_matieres->fetchAll();
        
        if (empty($matieres)) {
            error_log("Aucune matière trouvée pour la classe ID: " . $etudiant['classe_id']);
            return [
                'etudiant' => $etudiant,
                'notes_detail' => [],
                'total_points' => 0,
                'total_credits' => 0,
                'moyenne_generale' => null,
                'decision' => 'non évalué',
                'mention' => 'Non évalué'
            ];
        }
        
        $total_points = 0;
        $total_credits = 0;
        $notes_detail = [];
        
        foreach ($matieres as $matiere) {
            $matiere_id = $matiere['id'];
            
            // Récupérer les notes de l'étudiant pour cette matière
            $sql_notes = "SELECT n.note, te.nom as type_examen, te.pourcentage
                         FROM notes n 
                         JOIN types_examens te ON n.type_examen_id = te.id 
                         WHERE n.etudiant_id = ? 
                           AND n.matiere_id = ? 
                           AND n.semestre_id = ? 
                           AND n.annee_academique_id = ? 
                           AND n.statut = 'valide'
                         ORDER BY te.ordre";
            
            $stmt_notes = $db->prepare($sql_notes);
            $stmt_notes->execute([$etudiant_id, $matiere_id, $semestre_id, $annee_id]);
            $notes = $stmt_notes->fetchAll();
            
            // Identifier les notes par type d'examen
            $dst_note = null;
            $recherche_note = null;
            $session_note = null;
            
            foreach ($notes as $note) {
                $type = trim(strtolower($note['type_examen']));
                
                if ($type === 'dst') {
                    $dst_note = floatval($note['note']);
                } elseif ($type === 'devoir de recherche') {
                    $recherche_note = floatval($note['note']);
                } elseif ($type === 'session') {
                    $session_note = floatval($note['note']);
                }
            }
            
            // Calculer la note finale si toutes les notes sont présentes
            $note_finale = null;
            if ($dst_note !== null && $recherche_note !== null && $session_note !== null) {
                $note_finale = ($dst_note * 0.20) + ($recherche_note * 0.20) + ($session_note * 0.60);
                $note_finale = round($note_finale, 2);
            }
            
            $credit = $matiere['credit'] ?? $matiere['coefficient'] ?? 1;
            $points = $note_finale !== null ? round($note_finale * $credit, 2) : null;
            
            if ($note_finale !== null) {
                $total_points += $points;
                $total_credits += $credit;
            }
            
            $notes_detail[] = [
                'matiere' => $matiere,
                'dst' => $dst_note,
                'recherche' => $recherche_note,
                'session' => $session_note,
                'note_finale' => $note_finale,
                'credit' => $credit,
                'points' => $points
            ];
        }
        
        $moyenne_generale = $total_credits > 0 ? round($total_points / $total_credits, 2) : null;
        
        return [
            'etudiant' => $etudiant,
            'notes_detail' => $notes_detail,
            'total_points' => $total_points,
            'total_credits' => $total_credits,
            'moyenne_generale' => $moyenne_generale,
            'decision' => $moyenne_generale >= 10 ? 'admis' : 'ajourne',
            'mention' => getMention($moyenne_generale)
        ];
        
    } catch (Exception $e) {
        error_log("Erreur calculerMoyenneEtudiant: " . $e->getMessage());
        return null;
    }
}

function getRangEtudiant($db, $etudiant_id, $classe_id, $semestre_id, $annee_id) {
    try {
        // Récupérer tous les étudiants de la classe
        $etudiants = getEtudiantsParClasse($db, $classe_id);
        $moyennes = [];
        
        foreach ($etudiants as $etud) {
            $resultat = calculerMoyenneEtudiant($db, $etud['id'], $semestre_id, $annee_id);
            if ($resultat && $resultat['moyenne_generale'] !== null) {
                $moyennes[$etud['id']] = $resultat['moyenne_generale'];
            } else {
                $moyennes[$etud['id']] = 0;
            }
        }
        
        // Trier par moyenne décroissante
        arsort($moyennes);
        
        // Trouver le rang
        $rang = 1;
        foreach ($moyennes as $id => $moyenne) {
            if ($id == $etudiant_id) {
                return $rang;
            }
            $rang++;
        }
        
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function enregistrerBulletin($db, $data) {
    try {
        // Vérifier si un bulletin existe déjà
        $sql_check = "SELECT id FROM bulletins 
                      WHERE etudiant_id = ? 
                        AND annee_academique_id = ? 
                        AND semestre_id = ?";
        
        $stmt_check = $db->prepare($sql_check);
        $stmt_check->execute([$data['etudiant_id'], $data['annee_academique_id'], $data['semestre_id']]);
        $existing = $stmt_check->fetch();
        
        // D'abord, insérer ou mettre à jour le bulletin sans QR code
        if ($existing) {
            // Mettre à jour le bulletin existant
            $sql = "UPDATE bulletins SET 
                    moyenne_generale = ?,
                    rang = ?,
                    effectif_classe = ?,
                    appreciation = ?,
                    decision = ?,
                    date_modification = NOW(),
                    statut = 'valide'
                    WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $success = $stmt->execute([
                $data['moyenne_generale'],
                $data['rang'],
                $data['effectif_classe'],
                $data['appreciation'],
                $data['decision'],
                $existing['id']
            ]);
            
            $bulletin_id = $existing['id'];
        } else {
            // Créer un nouveau bulletin
            $sql = "INSERT INTO bulletins (
                    etudiant_id,
                    annee_academique_id,
                    semestre_id,
                    moyenne_generale,
                    rang,
                    effectif_classe,
                    appreciation,
                    decision,
                    date_edition,
                    edite_par,
                    statut
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, 'valide')";
            
            $stmt = $db->prepare($sql);
            $success = $stmt->execute([
                $data['etudiant_id'],
                $data['annee_academique_id'],
                $data['semestre_id'],
                $data['moyenne_generale'],
                $data['rang'],
                $data['effectif_classe'],
                $data['appreciation'],
                $data['decision'],
                $_SESSION['user_id']
            ]);
            
            $bulletin_id = $db->lastInsertId();
        }
        
        if (!$success) {
            return ['success' => false, 'error' => 'Erreur lors de l\'enregistrement du bulletin'];
        }
        
        // Maintenant générer le QR code
        $qr_result = genererQRCodeBulletin($db, $bulletin_id, 
            $data['etudiant_id'], 
            $data['annee_academique_id'], 
            $data['semestre_id']
        );
        
        if (isset($qr_result['error'])) {
            // Si erreur de QR code, on retourne quand même le bulletin mais sans QR
            return [
                'success' => true,
                'bulletin_id' => $bulletin_id,
                'warning' => 'Bulletin généré sans QR code: ' . $qr_result['error']
            ];
        }
        
        // Mettre à jour avec le QR code
        $sql_update_qr = "UPDATE bulletins SET 
                         qr_code = ?,
                         qr_code_url = ?
                         WHERE id = ?";
        
        $stmt_update = $db->prepare($sql_update_qr);
        $stmt_update->execute([
            basename($qr_result['qr_path']),
            $qr_result['qr_url'],
            $bulletin_id
        ]);
        
        // Stocker les données du bulletin en session pour l'affichage
        $_SESSION['bulletin_data'] = [
            'bulletin_id' => $bulletin_id,
            'etudiant_id' => $data['etudiant_id'],
            'annee_id' => $data['annee_academique_id'],
            'semestre_id' => $data['semestre_id'],
            'qr_url' => $qr_result['qr_url'],
            'moyenne_generale' => $data['moyenne_generale'],
            'rang' => $data['rang'],
            'effectif_classe' => $data['effectif_classe'],
            'decision' => $data['decision'],
            'appreciation' => $data['appreciation']
        ];
        
        return [
            'success' => true,
            'bulletin_id' => $bulletin_id,
            'qr_url' => $qr_result['qr_url']
        ];
        
    } catch (Exception $e) {
        error_log("Erreur enregistrerBulletin: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur base de données: ' . $e->getMessage()];
    }
}

// ============================================
// 6. TRAITEMENT DES REQUÊTES
// ============================================
// Récupérer le nom du site
$site_id = $_SESSION['site_id'] ?? 1;
$site_name = $_SESSION['site_name'] ?? 'ISGI';

$annees = getAnneesAcademiques($db);
$semestres = getSemestres($db);
$classes = getClasses($db);
$etudiants = [];

$selected_annee = $_GET['annee_id'] ?? null;
$selected_semestre = $_GET['semestre_id'] ?? null;
$selected_classe = $_GET['classe_id'] ?? null;
$selected_etudiant = $_GET['etudiant_id'] ?? null;

$resultat_calcul = null;
$bulletin_result = null;
$bulletin_generated = false;

// Récupérer les étudiants si une classe est sélectionnée
if ($selected_classe) {
    $etudiants = getEtudiantsParClasse($db, $selected_classe);
}

// Calculer la moyenne si un étudiant est sélectionné
if ($selected_etudiant && $selected_semestre && $selected_annee) {
    $resultat_calcul = calculerMoyenneEtudiant($db, $selected_etudiant, $selected_semestre, $selected_annee);
    
    if ($resultat_calcul && $selected_classe) {
        // Calculer le rang
        $rang = getRangEtudiant($db, $selected_etudiant, $selected_classe, $selected_semestre, $selected_annee);
        $resultat_calcul['rang'] = $rang;
        $resultat_calcul['effectif_classe'] = count($etudiants);
        $resultat_calcul['appreciation'] = getAppreciation($resultat_calcul['moyenne_generale']);
        
        // Récupérer les infos d'année et semestre
        foreach ($annees as $a) {
            if ($a['id'] == $selected_annee) {
                $resultat_calcul['annee_academique'] = $a['libelle'] ?? 'Non défini';
                break;
            }
        }
        
        foreach ($semestres as $s) {
            if ($s['id'] == $selected_semestre) {
                $resultat_calcul['semestre'] = 'Semestre ' . ($s['numero'] ?? '?');
                break;
            }
        }
    }
}

// Générer le bulletin
if (isset($_POST['generer_bulletin']) && $resultat_calcul) {
    $bulletin_data = [
        'etudiant_id' => $selected_etudiant,
        'annee_academique_id' => $selected_annee,
        'semestre_id' => $selected_semestre,
        'moyenne_generale' => $resultat_calcul['moyenne_generale'] ?? 0,
        'rang' => $resultat_calcul['rang'] ?? 0,
        'effectif_classe' => $resultat_calcul['effectif_classe'] ?? 0,
        'appreciation' => $resultat_calcul['appreciation'] ?? '',
        'decision' => $resultat_calcul['decision'] ?? 'ajourne'
    ];
    
    $bulletin_result = enregistrerBulletin($db, $bulletin_data);
    $bulletin_generated = true;
    
    // Stocker les données du bulletin en session
    $_SESSION['current_bulletin'] = [
        'resultat_calcul' => $resultat_calcul,
        'bulletin_result' => $bulletin_result,
        'selected_annee' => $selected_annee,
        'selected_semestre' => $selected_semestre,
        'selected_classe' => $selected_classe,
        'selected_etudiant' => $selected_etudiant
    ];
}

// Redirection vers la page d'affichage du bulletin
if (isset($_GET['voir_bulletin']) && isset($_GET['bulletin_id'])) {
    // Stocker l'ID du bulletin en session
    $_SESSION['view_bulletin_id'] = $_GET['bulletin_id'];
    $_SESSION['view_annee_id'] = $selected_annee;
    $_SESSION['view_semestre_id'] = $selected_semestre;
    $_SESSION['view_classe_id'] = $selected_classe;
    $_SESSION['view_etudiant_id'] = $selected_etudiant;
    
    // Rediriger vers la page d'affichage
    header('Location: voir_bulletin.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Bulletins - DAC | ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ========== VARIABLES CSS ========== */
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --info-color: #17a2b8;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 250px;
            --sidebar-collapsed: 70px;
        }
        
        /* ========== STYLES GÉNÉRAUX ========== */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #333;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        /* ========== LAYOUT PRINCIPAL ========== */
        .app-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }
        
        /* ========== SIDEBAR ========== */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color) 0%, #1a252f 100%);
            color: white;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            box-shadow: 3px 0 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            background: rgba(0,0,0,0.2);
        }
        
        .sidebar-logo {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--info-color), #0d8abc);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .sidebar-logo i {
            font-size: 24px;
        }
        
        .user-info {
            text-align: center;
            padding: 20px 15px;
            background: rgba(0,0,0,0.15);
            margin: 15px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-info p {
            margin: 0;
            font-weight: 500;
        }
        
        .user-role {
            display: inline-block;
            padding: 5px 15px;
            background: linear-gradient(135deg, var(--info-color), #0d8abc);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-top: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        /* ========== NAVIGATION ========== */
        .sidebar-nav {
            padding: 20px 15px;
        }
        
        .nav-section {
            margin-bottom: 30px;
        }
        
        .nav-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.5);
            margin-bottom: 12px;
            padding: 0 15px;
            font-weight: 600;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 5px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-left-color: var(--info-color);
            transform: translateX(5px);
        }
        
        .nav-link.active {
            background: linear-gradient(90deg, rgba(23, 162, 184, 0.2), transparent);
            color: white;
            border-left-color: var(--info-color);
            font-weight: 500;
        }
        
        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 16px;
            margin-right: 12px;
            opacity: 0.9;
        }
        
        .nav-badge {
            margin-left: auto;
            background: var(--danger-color);
            color: white;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        /* ========== CONTENU PRINCIPAL ========== */
        .main-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 25px;
            transition: all 0.3s ease;
        }
        
        /* ========== EN-TÊTE ========== */
        .content-header {
            background: white;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e9ecef;
        }
        
        .page-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .page-subtitle {
            color: #6c757d;
            font-size: 14px;
        }
        
        /* ========== CARTES DE CONTENU ========== */
        .content-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .card-header {
            background: linear-gradient(90deg, #f8f9fa, #e9ecef);
            border-bottom: 1px solid #dee2e6;
            padding: 18px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-header h5 {
            margin: 0;
            color: var(--primary-color);
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .card-header h5 i {
            margin-right: 10px;
            color: var(--info-color);
        }
        
        .card-body {
            padding: 25px;
        }
        
        /* ========== BOUTONS ========== */
        .btn-action {
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-action i {
            margin-right: 8px;
        }
        
        /* ========== FORMULAIRES ========== */
        .select-group {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #dee2e6;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        
        .form-select, .form-control {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--info-color);
            box-shadow: 0 0 0 0.2rem rgba(23, 162, 184, 0.25);
        }
        
        /* ========== CARTES ÉTUDIANTS ========== */
        .student-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            overflow: hidden;
            height: 100%;
            background: white;
        }
        
        .student-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border-color: var(--info-color);
        }
        
        .student-card .card-body {
            padding: 20px;
        }
        
        .student-card h6 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .note-badge {
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .note-excellente { 
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .note-bonne { 
            background: linear-gradient(135deg, #c3e6cb, #b1dfbb);
            color: #155724;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .note-moyenne { 
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        
        .note-faible { 
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        
        /* ========== TABLES ========== */
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background: linear-gradient(90deg, var(--info-color), #0d8abc);
            color: white;
            border: none;
            padding: 15px 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        
        .table tbody td {
            padding: 15px 20px;
            vertical-align: middle;
            border-color: #e9ecef;
        }
        
        .table tbody tr:hover {
            background-color: rgba(23, 162, 184, 0.05);
        }
        
        .grades-table tr:last-child {
            background: linear-gradient(90deg, rgba(23, 162, 184, 0.1), rgba(23, 162, 184, 0.05));
            font-weight: 600;
        }
        
        /* ========== QR CODE ========== */
        .qr-code-container {
            text-align: center;
            padding: 25px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            margin: 20px 0;
        }
        
        .qr-code-container img {
            max-width: 180px;
            border: 5px solid white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        /* ========== STATS BOXES ========== */
        .stats-box {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stats-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .stats-box h3 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .stats-success h3 { color: var(--success-color); }
        .stats-info h3 { color: var(--info-color); }
        .stats-warning h3 { color: var(--warning-color); }
        .stats-danger h3 { color: var(--danger-color); }
        
        /* ========== ALERTS PERSONNALISÉS ========== */
        .alert-custom {
            border-radius: 10px;
            border: none;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .alert-info {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.1), rgba(23, 162, 184, 0.05));
            border-left: 4px solid var(--info-color);
        }
        
        .alert-success {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-left: 4px solid var(--success-color);
        }
        
        .alert-warning {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1), rgba(255, 193, 7, 0.05));
            border-left: 4px solid var(--warning-color);
        }
        
        .alert-danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            border-left: 4px solid var(--danger-color);
        }
        
        /* ========== RESPONSIVE ========== */
        @media (max-width: 992px) {
            .sidebar {
                width: var(--sidebar-collapsed);
            }
            
            .sidebar-header h5,
            .user-info,
            .nav-section-title,
            .nav-link span,
            .nav-badge {
                display: none !important;
            }
            
            .sidebar-logo {
                width: 40px;
                height: 40px;
            }
            
            .sidebar-logo i {
                font-size: 18px;
            }
            
            .nav-link {
                justify-content: center;
                padding: 15px;
                margin-bottom: 10px;
            }
            
            .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }
            
            .main-content {
                margin-left: var(--sidebar-collapsed);
                padding: 15px;
            }
            
            .content-header {
                padding: 20px;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .table thead th,
            .table tbody td {
                padding: 12px 15px;
            }
        }
        
        @media (max-width: 576px) {
            .sidebar {
                display: none;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .mobile-menu-btn {
                display: block !important;
            }
        }
        
        /* ========== UTILITAIRES ========== */
        .text-info { color: var(--info-color) !important; }
        .bg-info { background-color: var(--info-color) !important; }
        .text-primary { color: var(--primary-color) !important; }
        .bg-primary { background-color: var(--primary-color) !important; }
        .text-success { color: var(--success-color) !important; }
        .bg-success { background-color: var(--success-color) !important; }
        .text-warning { color: var(--warning-color) !important; }
        .bg-warning { background-color: var(--warning-color) !important; }
        .text-danger { color: var(--danger-color) !important; }
        .bg-danger { background-color: var(--danger-color) !important; }
        
        /* ========== BOUTON MOBILE ========== */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            background: var(--info-color);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
        }
        
        /* ========== ANIMATIONS ========== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }
        
        /* ========== SCROLLBAR PERSONNALISÉE ========== */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--info-color);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #0d8abc;
        }
    </style>
</head>
<body>
    <!-- Bouton menu mobile -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    
    <div class="app-container">
        <!-- ========== SIDEBAR ========== -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI DAC</h5>
                <div class="user-role">Directeur Académique</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur DAC'); ?></p>
                <small><?php echo htmlspecialchars($site_name); ?></small>
            </div>
            
            <div class="sidebar-nav">
                <!-- Tableau de bord -->
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de Bord</span>
                    </a>
                </div>
                
                <!-- Gestion des étudiants -->
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Étudiants</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Liste des étudiants</span>
                    </a>
                    <a href="cartes_etudiant.php" class="nav-link">
                        <i class="fas fa-id-card"></i>
                        <span>Cartes étudiant</span>
                    </a>
                    <a href="presences.php" class="nav-link">
                        <i class="fas fa-calendar-check"></i>
                        <span>Gestion présence</span>
                    </a>
                    <a href="salles.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Salles de classe</span>
                    </a>
                </div>
                
                <!-- Calendrier & Examens -->
                <div class="nav-section">
                    <div class="nav-section-title">Calendrier & Examens</div>
                    <a href="calendrier_academique.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier académique</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier examens</span>
                    </a>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions pédagogiques</span>
                    </a>
                </div>
                
                <!-- Notes & Évaluations -->
                <div class="nav-section">
                    <div class="nav-section-title">Notes & Évaluations</div>
                    <a href="matieres.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Assignation des classes</span>
                    </a>
                    <a href="notes.php" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Gestion des notes</span>
                    </a>
                    <a href="bulletins.php" class="nav-link active">
                        <i class="fas fa-file-certificate"></i>
                        <span>Bulletins de notes</span>
                    </a>
                    <a href="assignation_matieres.php" class="nav-link">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Assignation des matières</span>
                    </a>
                </div>
                
                <!-- Rapports & Statistiques -->
                <div class="nav-section">
                    <div class="nav-section-title">Rapports & Statistiques</div>
                    <a href="rapports_academiques.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports académiques</span>
                    </a>
                    <a href="statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Statistiques détaillées</span>
                    </a>
                    <a href="export_data.php" class="nav-link">
                        <i class="fas fa-download"></i>
                        <span>Export des données</span>
                    </a>
                </div>
                
                <!-- Compte -->
                <div class="nav-section">
                    <div class="nav-section-title">Compte</div>
                    <a href="../../auth/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- ========== CONTENU PRINCIPAL ========== -->
        <div class="main-content" id="mainContent">
            <!-- En-tête -->
            <div class="content-header fade-in">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-file-certificate me-2"></i>
                            Gestion des Bulletins de Notes
                        </h1>
                        <p class="page-subtitle">
                            <i class="fas fa-user me-1"></i> Bienvenue, <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur'); ?></strong> | 
                            <i class="fas fa-map-marker-alt me-1"></i> Site: <strong><?php echo htmlspecialchars($site_name); ?></strong> | 
                            <i class="fas fa-calendar me-1"></i> <?php echo date('d/m/Y'); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <a href="dashboard.php" class="btn btn-info btn-action">
                            <i class="fas fa-tachometer-alt me-2"></i> Tableau de bord
                        </a>
                        <button onclick="window.print()" class="btn btn-success btn-action">
                            <i class="fas fa-print me-2"></i> Imprimer
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- ========== CONTENU PRINCIPAL ========== -->
            <div class="row fade-in">
                <div class="col-12">
                    <div class="content-card">
                        <div class="card-header">
                            <h5><i class="fas fa-sliders-h me-2"></i>Sélection des Paramètres</h5>
                        </div>
                        <div class="card-body">
                            <!-- Formulaire de sélection -->
                            <form method="GET" action="" class="select-group" id="selectionForm">
                                <div class="row g-3">
                                    <!-- Année académique -->
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-calendar-alt me-1"></i>Année académique *
                                        </label>
                                        <select name="annee_id" class="form-select" required 
                                                onchange="this.form.submit()" <?php echo $selected_etudiant ? 'disabled' : ''; ?>>
                                            <option value="">Sélectionnez...</option>
                                            <?php foreach ($annees as $annee): ?>
                                                <option value="<?php echo $annee['id']; ?>" 
                                                        <?php echo ($selected_annee == $annee['id']) ? 'selected' : ''; ?>>
                                                    <?php echo safe_html($annee['libelle'] ?? ''); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Semestre -->
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-layer-group me-1"></i>Semestre *
                                        </label>
                                        <select name="semestre_id" class="form-select" required 
                                                onchange="this.form.submit()" 
                                                <?php echo !$selected_annee || $selected_etudiant ? 'disabled' : ''; ?>>
                                            <option value="">Sélectionnez...</option>
                                            <?php foreach ($semestres as $semestre): ?>
                                                <option value="<?php echo $semestre['id']; ?>" 
                                                        <?php echo ($selected_semestre == $semestre['id']) ? 'selected' : ''; ?>>
                                                    <?php echo safe_html($semestre['nom'] ?? 'Semestre ' . ($semestre['numero'] ?? '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Classe -->
                                    <div class="col-md-3">
                                        <label class="form-label fw-bold">
                                            <i class="fas fa-users-class me-1"></i>Classe *
                                        </label>
                                        <select name="classe_id" class="form-select" required 
                                                onchange="this.form.submit()" 
                                                <?php echo !$selected_semestre || $selected_etudiant ? 'disabled' : ''; ?>>
                                            <option value="">Sélectionnez...</option>
                                            <?php foreach ($classes as $classe): ?>
                                                <option value="<?php echo $classe['id']; ?>" 
                                                        <?php echo ($selected_classe == $classe['id']) ? 'selected' : ''; ?>>
                                                    <?php echo safe_html($classe['nom'] ?? ''); ?>
                                                    <?php if (isset($classe['filiere_nom'])): ?>
                                                    (<?php echo safe_html($classe['filiere_nom']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3 d-flex align-items-end">
                                        <?php if ($selected_etudiant): ?>
                                            <a href="bulletins.php" class="btn btn-outline-secondary w-100 btn-action">
                                                <i class="fas fa-redo me-2"></i> Recommencer
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                            
                            <!-- Liste des étudiants -->
                            <?php if ($selected_classe && !$selected_etudiant): ?>
                            <div class="row mt-4">
                                <div class="col-12">
                                    <h5 class="mb-3">
                                        <i class="fas fa-user-graduate me-2"></i>
                                        Liste des Étudiants - 
                                        <span class="text-info"><?php 
                                            foreach($classes as $c) {
                                                if($c['id'] == $selected_classe) {
                                                    echo safe_html($c['nom']);
                                                    break;
                                                }
                                            }
                                        ?></span>
                                    </h5>
                                </div>
                                
                                <?php if (empty($etudiants)): ?>
                                <div class="col-12">
                                    <div class="alert alert-warning text-center">
                                        <i class="fas fa-users-slash fa-2x mb-3"></i>
                                        <h5>Aucun étudiant trouvé</h5>
                                        <p class="mb-0">Cette classe ne contient aucun étudiant.</p>
                                    </div>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($etudiants as $etudiant): 
                                        $resultat = calculerMoyenneEtudiant($db, $etudiant['id'], $selected_semestre, $selected_annee);
                                        $moyenne = $resultat ? $resultat['moyenne_generale'] : null;
                                        
                                        // Déterminer la classe CSS pour la note
                                        $note_class = '';
                                        if ($moyenne >= 16) $note_class = 'note-excellente';
                                        elseif ($moyenne >= 14) $note_class = 'note-bonne';
                                        elseif ($moyenne >= 10) $note_class = 'note-moyenne';
                                        elseif ($moyenne !== null) $note_class = 'note-faible';
                                    ?>
                                    <div class="col-xl-3 col-md-4 col-sm-6 mb-4">
                                        <a href="?annee_id=<?php echo $selected_annee; ?>&semestre_id=<?php echo $selected_semestre; ?>&classe_id=<?php echo $selected_classe; ?>&etudiant_id=<?php echo $etudiant['id']; ?>" 
                                           class="text-decoration-none">
                                            <div class="student-card">
                                                <div class="card-body">
                                                    <div class="d-flex align-items-start mb-3">
                                                        <div class="flex-shrink-0">
                                                            <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center" 
                                                                 style="width: 50px; height: 50px;">
                                                                <i class="fas fa-user"></i>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1 ms-3">
                                                            <h6 class="mb-1">
                                                                <?php echo safe_html(($etudiant['prenom'] ?? '') . ' ' . ($etudiant['nom'] ?? '')); ?>
                                                            </h6>
                                                            <p class="mb-0 small text-muted">
                                                                <i class="fas fa-id-card"></i> <?php echo safe_html($etudiant['matricule'] ?? ''); ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($moyenne !== null): ?>
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="badge note-badge <?php echo $note_class; ?>">
                                                            <i class="fas fa-chart-line me-1"></i>
                                                            <?php echo number_format($moyenne, 2); ?>/20
                                                        </span>
                                                        <span class="badge bg-<?php echo $moyenne >= 10 ? 'success' : 'danger'; ?>">
                                                            <?php echo $moyenne >= 10 ? 'Admis' : 'Ajourné'; ?>
                                                        </span>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="badge bg-secondary w-100 py-2">
                                                        <i class="fas fa-exclamation-circle me-1"></i>Non évalué
                                                    </span>
                                                    <?php endif; ?>
                                                    
                                                    <div class="text-center mt-3">
                                                        <small class="text-info">
                                                            <i class="fas fa-hand-pointer me-1"></i>Cliquer pour voir les détails
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Résultats et génération -->
                            <?php if ($resultat_calcul): ?>
                            <div class="row mt-4">
                                <div class="col-lg-8">
                                    <div class="content-card">
                                        <div class="card-header">
                                            <h5><i class="fas fa-chart-line me-2"></i>Résultats Détailés</h5>
                                        </div>
                                        <div class="card-body">
                                            <!-- Informations étudiant -->
                                            <div class="row mb-4">
                                                <div class="col-md-6">
                                                    <div class="d-flex align-items-center mb-3">
                                                        <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center me-3" 
                                                             style="width: 60px; height: 60px;">
                                                            <i class="fas fa-user-graduate fa-lg"></i>
                                                        </div>
                                                        <div>
                                                            <h5 class="mb-1">
                                                                <?php echo safe_html(($resultat_calcul['etudiant']['prenom'] ?? '') . ' ' . ($resultat_calcul['etudiant']['nom'] ?? '')); ?>
                                                            </h5>
                                                            <p class="mb-0 text-muted">
                                                                <i class="fas fa-id-card"></i> <?php echo safe_html($resultat_calcul['etudiant']['matricule'] ?? 'Non défini'); ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="bg-light p-3 rounded">
                                                        <div class="row">
                                                            <div class="col-6">
                                                                <p class="mb-1"><strong>Classe:</strong></p>
                                                                <p class="mb-1"><strong>Année/Semestre:</strong></p>
                                                            </div>
                                                            <div class="col-6">
                                                                <p class="mb-1"><?php echo safe_html($resultat_calcul['etudiant']['classe_nom'] ?? 'Non défini'); ?></p>
                                                                <p class="mb-0"><?php echo safe_html(($resultat_calcul['annee_academique'] ?? 'Non défini') . ' - ' . ($resultat_calcul['semestre'] ?? 'Non défini')); ?></p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Tableau des notes -->
                                            <?php if (!empty($resultat_calcul['notes_detail'])): ?>
                                            <div class="table-container mt-4">
                                                <table class="table table-hover grades-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Matière</th>
                                                            <th>Code</th>
                                                            <th class="text-center">DST</th>
                                                            <th class="text-center">Recherche</th>
                                                            <th class="text-center">Session</th>
                                                            <th class="text-center">Finale</th>
                                                            <th class="text-center">Crédit</th>
                                                            <th class="text-center">Points</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($resultat_calcul['notes_detail'] as $note): 
                                                            $note_finale = $note['note_finale'] ?? null;
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <div class="fw-bold"><?php echo safe_html($note['matiere']['nom'] ?? 'Matière inconnue'); ?></div>
                                                                <small class="text-muted"><?php echo safe_html($note['matiere']['description'] ?? ''); ?></small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-info"><?php echo safe_html($note['matiere']['code'] ?? ''); ?></span>
                                                            </td>
                                                            <td class="text-center fw-bold <?php echo ($note['dst'] ?? 0) >= 10 ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo $note['dst'] ?? '-'; ?>
                                                            </td>
                                                            <td class="text-center fw-bold <?php echo ($note['recherche'] ?? 0) >= 10 ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo $note['recherche'] ?? '-'; ?>
                                                            </td>
                                                            <td class="text-center fw-bold <?php echo ($note['session'] ?? 0) >= 10 ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo $note['session'] ?? '-'; ?>
                                                            </td>
                                                            <td class="text-center fw-bold <?php echo $note_finale >= 10 ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo $note_finale ?? '-'; ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <span class="badge bg-secondary"><?php echo $note['credit'] ?? '0'; ?></span>
                                                            </td>
                                                            <td class="text-center fw-bold <?php echo $note['points'] && $note['points'] >= ($note['credit'] * 10) ? 'text-success' : 'text-danger'; ?>">
                                                                <?php echo $note['points'] ?? '-'; ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                        
                                                        <tr class="table-primary">
                                                            <td colspan="6" class="text-end fw-bold">
                                                                <i class="fas fa-calculator me-2"></i>MOYENNE GÉNÉRALE
                                                            </td>
                                                            <td class="text-center fw-bold">
                                                                <?php echo $resultat_calcul['total_credits'] ?? '0'; ?>
                                                            </td>
                                                            <td class="text-center fw-bold fs-5">
                                                                <?php echo number_format($resultat_calcul['moyenne_generale'] ?? 0, 2); ?> / 20
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-warning text-center">
                                                <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                                                <h5>Aucune note trouvée</h5>
                                                <p class="mb-0">Cet étudiant n'a pas de notes pour les matières de cette classe.</p>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Récapitulatif -->
                                            <div class="row mt-4">
                                                <div class="col-md-3 mb-3">
                                                    <div class="stats-box stats-info">
                                                        <i class="fas fa-chart-line fa-2x mb-3"></i>
                                                        <h3><?php echo $resultat_calcul['moyenne_generale'] ?? 'N/A'; ?>/20</h3>
                                                        <p class="mb-0">Moyenne Générale</p>
                                                    </div>
                                                </div>
                                                <div class="col-md-3 mb-3">
                                                    <div class="stats-box stats-warning">
                                                        <i class="fas fa-trophy fa-2x mb-3"></i>
                                                        <h3><?php echo $resultat_calcul['rang'] ?? 'N/A'; ?>ème</h3>
                                                        <p class="mb-0">Rang / <?php echo $resultat_calcul['effectif_classe'] ?? '0'; ?></p>
                                                    </div>
                                                </div>
                                                <div class="col-md-3 mb-3">
                                                    <div class="stats-box <?php echo ($resultat_calcul['decision'] ?? '') == 'admis' ? 'stats-success' : 'stats-danger'; ?>">
                                                        <i class="fas fa-graduation-cap fa-2x mb-3"></i>
                                                        <h3><?php echo ucfirst($resultat_calcul['decision'] ?? 'non défini'); ?></h3>
                                                        <p class="mb-0">Décision</p>
                                                    </div>
                                                </div>
                                                <div class="col-md-3 mb-3">
                                                    <div class="stats-box stats-info">
                                                        <i class="fas fa-award fa-2x mb-3"></i>
                                                        <h3><?php echo $resultat_calcul['mention'] ?? 'N/A'; ?></h3>
                                                        <p class="mb-0">Mention</p>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Appréciation -->
                                            <div class="alert alert-info alert-custom mt-3">
                                                <div class="d-flex align-items-start">
                                                    <i class="fas fa-comment fa-2x me-3"></i>
                                                    <div>
                                                        <h6 class="fw-bold mb-2"><i class="fas fa-pen me-1"></i>Appréciation du Directeur Académique:</h6>
                                                        <p class="mb-2"><?php echo safe_html($resultat_calcul['appreciation'] ?? ''); ?></p>
                                                        <div class="border-top pt-2 mt-2">
                                                            <small class="text-muted">
                                                                <i class="fas fa-calendar me-1"></i> Date d'édition: <?php echo date('d/m/Y'); ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Panneau de génération -->
                                <div class="col-lg-4">
                                    <div class="content-card">
                                        <div class="card-header">
                                            <h5><i class="fas fa-file-certificate me-2"></i>Génération du Bulletin</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php if ($bulletin_result && $bulletin_result['success']): ?>
                                            <div class="alert alert-success alert-custom">
                                                <div class="d-flex align-items-start">
                                                    <i class="fas fa-check-circle fa-2x me-3"></i>
                                                    <div>
                                                        <h6 class="fw-bold mb-2">Bulletin généré avec succès!</h6>
                                                        <p class="mb-2">
                                                            <i class="fas fa-hashtag me-1"></i>
                                                            ID: <strong>BUL-<?php echo str_pad($bulletin_result['bulletin_id'] ?? '0000', 5, '0', STR_PAD_LEFT); ?></strong>
                                                        </p>
                                                        
                                                        <?php if (isset($bulletin_result['warning'])): ?>
                                                        <div class="alert alert-warning mt-2">
                                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                                            <?php echo safe_html($bulletin_result['warning']); ?>
                                                        </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (isset($bulletin_result['qr_url']) && !empty($bulletin_result['qr_url'])): 
                                                            $qr_full_path = ROOT_PATH . $bulletin_result['qr_url'];
                                                            if (file_exists($qr_full_path)): ?>
                                                            <div class="qr-code-container">
                                                                <img src="<?php echo $bulletin_result['qr_url']; ?>" 
                                                                     alt="QR Code" class="img-fluid mb-3">
                                                                <p class="mb-2 text-success">
                                                                    <i class="fas fa-check-circle me-1"></i>
                                                                    QR code de validation généré
                                                                </p>
                                                                
                                                                <div class="d-grid gap-2">
                                                                    <!-- Bouton pour voir le bulletin -->
                                                                    <a href="voir_bulletin.php?bulletin_id=<?php echo $bulletin_result['bulletin_id']; ?>&source=generate" 
                                                                       target="_blank" class="btn btn-primary btn-action">
                                                                        <i class="fas fa-eye me-2"></i>Voir le Bulletin
                                                                    </a>
                                                                    
                                                                    <!-- Bouton pour imprimer -->
                                                                    <button onclick="printBulletin()" class="btn btn-success btn-action">
                                                                        <i class="fas fa-print me-2"></i>Imprimer le Bulletin
                                                                    </button>
                                                                    
                                                                    <a href="?annee_id=<?php echo $selected_annee; ?>&semestre_id=<?php echo $selected_semestre; ?>&classe_id=<?php echo $selected_classe; ?>" 
                                                                       class="btn btn-outline-info btn-action">
                                                                        <i class="fas fa-redo me-2"></i>Générer un autre
                                                                    </a>
                                                                </div>
                                                            </div>
                                                            <?php else: ?>
                                                            <div class="alert alert-warning mt-3">
                                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                                QR code généré mais fichier non trouvé sur le serveur.
                                                            </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php elseif ($bulletin_result && !$bulletin_result['success']): ?>
                                            <div class="alert alert-danger alert-custom">
                                                <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                                                <h6 class="fw-bold">Erreur lors de la génération</h6>
                                                <p class="mb-0"><?php echo safe_html($bulletin_result['error'] ?? 'Erreur inconnue'); ?></p>
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-light alert-custom">
                                                <div class="d-flex align-items-start">
                                                    <i class="fas fa-info-circle fa-2x me-3 text-info"></i>
                                                    <div>
                                                        <h6 class="fw-bold mb-3">
                                                            <i class="fas fa-file-certificate me-2"></i>Générer le Bulletin Officiel
                                                        </h6>
                                                        <p class="mb-3">
                                                            Cliquez sur le bouton ci-dessous pour créer un bulletin officiel 
                                                            avec QR code de validation intégré.
                                                        </p>
                                                        
                                                        <form method="POST" action="" id="generateForm">
                                                            <button type="submit" name="generer_bulletin" class="btn btn-primary w-100 btn-action">
                                                                <i class="fas fa-file-certificate me-2"></i>Générer le Bulletin
                                                            </button>
                                                        </form>
                                                        
                                                        <div class="mt-4">
                                                            <h6 class="fw-bold mb-2">
                                                                <i class="fas fa-qrcode me-2"></i>Fonctionnalités incluses:
                                                            </h6>
                                                            <ul class="list-unstyled small">
                                                                <li class="mb-2"><i class="fas fa-check text-success me-2"></i>QR code de validation</li>
                                                                <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Signature numérique</li>
                                                                <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Horodatage sécurisé</li>
                                                                <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Version imprimable</li>
                                                                <li><i class="fas fa-check text-success me-2"></i>Archivage automatique</li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Actions rapides -->
                                    <div class="content-card mt-4">
                                        <div class="card-header">
                                            <h5><i class="fas fa-bolt me-2"></i> Actions Rapides</h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-grid gap-2">
                                                <a href="notes.php" class="btn btn-outline-info btn-action">
                                                    <i class="fas fa-file-alt me-2"></i>Gestion des Notes
                                                </a>
                                                <a href="matieres.php" class="btn btn-outline-warning btn-action">
                                                    <i class="fas fa-book me-2"></i>Matières
                                                </a>
                                                <a href="rapports_academiques.php" class="btn btn-outline-success btn-action">
                                                    <i class="fas fa-chart-bar me-2"></i>Rapports
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php elseif ($selected_etudiant && !$resultat_calcul): ?>
                            <div class="alert alert-warning alert-custom text-center">
                                <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                                <h4>Aucune donnée trouvée</h4>
                                <p class="mb-3">Cet étudiant n'a pas de notes pour la période sélectionnée.</p>
                                <a href="?annee_id=<?php echo $selected_annee; ?>&semestre_id=<?php echo $selected_semestre; ?>&classe_id=<?php echo $selected_classe; ?>" 
                                   class="btn btn-info btn-action">
                                    <i class="fas fa-arrow-left me-2"></i>Retour à la liste
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ========== PIED DE PAGE ========== -->
            <footer class="mt-5 pt-3 border-top text-center text-muted">
                <small>
                    &copy; <?php echo date('Y'); ?> ISGI - Système de Gestion Académique | 
                    Gestion des Bulletins | 
                    <span id="current-time"></span>
                </small>
            </footer>
        </div>
    </div>
    
    <!-- ========== SCRIPTS ========== -->
    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // ========== FONCTIONS UTILITAIRES ==========
    
    // Mettre à jour l'heure actuelle
    function updateCurrentTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('fr-FR', { 
            hour: '2-digit', 
            minute: '2-digit',
            second: '2-digit'
        });
        document.getElementById('current-time').textContent = timeString;
    }
    
    // Toggle sidebar sur mobile
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        
        if (window.innerWidth <= 576) {
            if (sidebar.style.display === 'block') {
                sidebar.style.display = 'none';
                mainContent.style.marginLeft = '0';
            } else {
                sidebar.style.display = 'block';
                sidebar.style.width = '250px';
                sidebar.style.position = 'fixed';
                sidebar.style.zIndex = '1000';
                mainContent.style.marginLeft = '0';
            }
        }
    }
    
    // Gérer le responsive du sidebar
    function handleSidebarResponsive() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mobileBtn = document.getElementById('mobileMenuBtn');
        
        if (window.innerWidth <= 576) {
            // Mobile: sidebar caché par défaut
            sidebar.style.display = 'none';
            mainContent.style.marginLeft = '0';
            mobileBtn.style.display = 'block';
        } else if (window.innerWidth <= 992) {
            // Tablet: sidebar réduit
            sidebar.style.display = 'block';
            sidebar.style.width = '70px';
            mainContent.style.marginLeft = '70px';
            mobileBtn.style.display = 'none';
        } else {
            // Desktop: sidebar complet
            sidebar.style.display = 'block';
            sidebar.style.width = '250px';
            mainContent.style.marginLeft = '250px';
            mobileBtn.style.display = 'none';
        }
    }
    
    // Confirmation pour les actions importantes
    function confirmAction(message) {
        return confirm(message || 'Êtes-vous sûr de vouloir effectuer cette action ?');
    }
    
    // ========== INITIALISATION ==========
    document.addEventListener('DOMContentLoaded', function() {
        // Initialiser l'heure
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);
        
        // Gérer le responsive
        handleSidebarResponsive();
        window.addEventListener('resize', handleSidebarResponsive);
        
        // Bouton menu mobile
        document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
        
        // Confirmation avant génération
        const generateForm = document.getElementById('generateForm');
        if (generateForm) {
            generateForm.addEventListener('submit', function(e) {
                if (!confirm('Voulez-vous générer le bulletin officiel ? Cette action est définitive.')) {
                    e.preventDefault();
                }
            });
        }
        
        // Réactiver les selects si on veut changer (double-clic)
        const selects = document.querySelectorAll('select[disabled]');
        selects.forEach(select => {
            select.addEventListener('dblclick', function() {
                this.disabled = false;
                this.style.cursor = 'pointer';
            });
        });
        
        // Animation des cartes étudiants
        const studentCards = document.querySelectorAll('.student-card');
        studentCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    });
    
    function printBulletin() {
        // Rediriger vers la page d'impression
        if (<?php echo isset($bulletin_result['bulletin_id']) ? 'true' : 'false'; ?>) {
            const url = 'voir_bulletin.php?bulletin_id=<?php echo $bulletin_result['bulletin_id'] ?? ''; ?>&print=1';
            window.open(url, '_blank');
        }
    }
    
    // Filtrer les étudiants par note
    function filterStudents(filter) {
        const cards = document.querySelectorAll('.student-card');
        cards.forEach(card => {
            const badge = card.querySelector('.note-badge');
            if (badge) {
                const text = badge.textContent.toLowerCase();
                let show = false;
                
                switch(filter) {
                    case 'all':
                        show = true;
                        break;
                    case 'admis':
                        show = card.querySelector('.bg-success');
                        break;
                    case 'ajourne':
                        show = card.querySelector('.bg-danger');
                        break;
                    case 'excellent':
                        show = badge.classList.contains('note-excellente');
                        break;
                    case 'good':
                        show = badge.classList.contains('note-bonne');
                        break;
                    case 'average':
                        show = badge.classList.contains('note-moyenne');
                        break;
                    case 'weak':
                        show = badge.classList.contains('note-faible');
                        break;
                    case 'nonevaluated':
                        show = badge.classList.contains('bg-secondary');
                        break;
                }
                
                card.parentElement.style.display = show ? 'block' : 'none';
            }
        });
    }
    </script>
</body>
</html>