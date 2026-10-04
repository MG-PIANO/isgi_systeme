<?php
// dashboard/dac/voir_bulletin.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Vérifier que l'utilisateur est connecté et est un DAC (role_id = 5)
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

require_once ROOT_PATH . '/config/database.php';
$db = Database::getInstance()->getConnection();

// Vérifier si on demande le téléchargement PDF
$download_pdf = isset($_GET['download_pdf']) && $_GET['download_pdf'] == '1';

// Récupérer l'ID du bulletin à afficher
$bulletin_id = $_GET['bulletin_id'] ?? $_SESSION['view_bulletin_id'] ?? null;

if (!$bulletin_id) {
    die("Aucun bulletin spécifié.");
}

// Récupérer les informations du bulletin
$sql = "SELECT b.*, 
               e.matricule, e.nom as etudiant_nom, e.prenom as etudiant_prenom,
               c.nom as classe_nom,
               aa.libelle as annee_academique,
               s.numero as semestre_numero
        FROM bulletins b
        JOIN etudiants e ON b.etudiant_id = e.id
        JOIN classes c ON e.classe_id = c.id
        JOIN annees_academiques aa ON b.annee_academique_id = aa.id
        JOIN semestres s ON b.semestre_id = s.id
        WHERE b.id = ?";
        
$stmt = $db->prepare($sql);
$stmt->execute([$bulletin_id]);
$bulletin = $stmt->fetch();

if (!$bulletin) {
    die("Bulletin non trouvé.");
}

// Fonction pour obtenir la mention
function getMention($moyenne) {
    if ($moyenne === null) return 'Non évalué';
    if ($moyenne >= 16) return 'Très Bien';
    if ($moyenne >= 14) return 'Bien';
    if ($moyenne >= 12) return 'Assez Bien';
    if ($moyenne >= 10) return 'Passable';
    return 'Échoué';
}

// Corriger le chemin du QR code pour WAMP avec sous-dossier
if (!empty($bulletin['qr_code_url'])) {
    if (strpos($bulletin['qr_code_url'], '/isgi_system/') === 0) {
        $bulletin['qr_code_final_url'] = $bulletin['qr_code_url'];
    } else {
        $bulletin['qr_code_final_url'] = '/isgi_system' . $bulletin['qr_code_url'];
    }
    
    $physical_path = ROOT_PATH . $bulletin['qr_code_url'];
    if (!file_exists($physical_path)) {
        $physical_path = str_replace('\\', '/', ROOT_PATH) . $bulletin['qr_code_url'];
        if (!file_exists($physical_path)) {
            $bulletin['qr_code_final_url'] = null;
        }
    }
}

// Récupérer les notes détaillées
$sql_notes = "SELECT n.*, m.nom as matiere_nom, te.nom as type_examen
              FROM notes n
              JOIN matieres m ON n.matiere_id = m.id
              JOIN types_examens te ON n.type_examen_id = te.id
              WHERE n.etudiant_id = ?
                AND n.semestre_id = ?
                AND n.annee_academique_id = ?
                AND n.statut = 'valide'
              ORDER BY m.nom, te.ordre";
              
$stmt_notes = $db->prepare($sql_notes);
$stmt_notes->execute([$bulletin['etudiant_id'], $bulletin['semestre_id'], $bulletin['annee_academique_id']]);
$notes = $stmt_notes->fetchAll();

// Organiser les notes par matière
$notes_par_matiere = [];
foreach ($notes as $note) {
    $matiere_id = $note['matiere_id'];
    if (!isset($notes_par_matiere[$matiere_id])) {
        $notes_par_matiere[$matiere_id] = [
            'nom' => $note['matiere_nom'],
            'notes' => []
        ];
    }
    $notes_par_matiere[$matiere_id]['notes'][] = $note;
}

// Calculer les notes finales par matière
$notes_detail = [];
foreach ($notes_par_matiere as $matiere_id => $matiere_data) {
    $dst_note = null;
    $recherche_note = null;
    $session_note = null;
    
    foreach ($matiere_data['notes'] as $note) {
        $type = trim(strtolower($note['type_examen']));
        
        if ($type === 'dst') {
            $dst_note = floatval($note['note']);
        } elseif ($type === 'devoir de recherche') {
            $recherche_note = floatval($note['note']);
        } elseif ($type === 'session') {
            $session_note = floatval($note['note']);
        }
    }
    
    // Calculer la note finale
    $note_finale = null;
    if ($dst_note !== null && $recherche_note !== null && $session_note !== null) {
        $note_finale = ($dst_note * 0.20) + ($recherche_note * 0.20) + ($session_note * 0.60);
        $note_finale = round($note_finale, 2);
    }
    
    $notes_detail[] = [
        'matiere_nom' => $matiere_data['nom'],
        'dst' => $dst_note,
        'recherche' => $recherche_note,
        'session' => $session_note,
        'note_finale' => $note_finale
    ];
}

// Fonction pour générer le PDF
function genererPDF($bulletin, $notes_detail) {
    // Inclure TCPDF
    require_once(ROOT_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php');
    
    // Créer une nouvelle instance de TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Définir les informations du document
    $pdf->SetCreator('ISGI System');
    $pdf->SetAuthor('ISGI - Institut Supérieur de Gestion Ingénierie');
    $pdf->SetTitle('Bulletin de Notes - ' . $bulletin['etudiant_nom'] . ' ' . $bulletin['etudiant_prenom']);
    $pdf->SetSubject('Bulletin académique officiel');
    
    // Supprimer l'en-tête et le pied de page par défaut
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Ajouter une page
    $pdf->AddPage();
    
    // Couleurs
    $primaryColor = array(30, 58, 95); // #1e3a5f
    $accentColor = array(42, 92, 154); // #2a5c9a
    $goldColor = array(212, 175, 55); // #d4af37
    
    // En-tête avec style
    $pdf->SetFillColorArray($primaryColor);
    $pdf->Rect(10, 10, 190, 30, 'F');
    
    // Ligne dorée en haut
    $pdf->SetFillColorArray($goldColor);
    $pdf->Rect(10, 10, 190, 3, 'F');
    
    // Logo (si disponible)
    $logo_path = ROOT_PATH . '/image/logo isgi.jpg';
    if (file_exists($logo_path)) {
        $pdf->Image($logo_path, 15, 15, 20, 20, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
    }
    
    // Titre
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(40, 15);
    $pdf->Cell(150, 10, 'INSTITUT SUPÉRIEUR DE GESTION INGENIERIE', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(40, 25);
    $pdf->Cell(150, 10, 'BULLETIN DE NOTES OFFICIEL', 0, 1, 'L');
    
    // Informations académiques
    $pdf->SetFillColor(240, 244, 248);
    $pdf->Rect(10, 45, 190, 15, 'F');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->SetXY(10, 45);
    $pdf->Cell(190, 5, "Année académique: " . $bulletin['annee_academique'] . " | Semestre: " . $bulletin['semestre_numero'] . " | Date d'édition: " . date('d/m/Y', strtotime($bulletin['date_edition'])) . " | N° Bulletin: " . str_pad($bulletin['id'], 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    
    // Informations étudiant
    $pdf->SetY(65);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColorArray($primaryColor);
    $pdf->Cell(190, 10, 'Informations de l\'Étudiant', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    // Tableau des informations
    $pdf->SetY(75);
    $pdf->Cell(95, 8, 'Nom complet: ' . $bulletin['etudiant_nom'] . ' ' . $bulletin['etudiant_prenom'], 0, 0, 'L');
    $pdf->Cell(95, 8, 'Matricule: ' . $bulletin['matricule'], 0, 1, 'L');
    
    $pdf->Cell(95, 8, 'Classe: ' . $bulletin['classe_nom'], 0, 0, 'L');
    $pdf->Cell(95, 8, 'Date d\'édition: ' . date('d/m/Y à H:i', strtotime($bulletin['date_edition'])), 0, 1, 'L');
    
    // Ligne de séparation
    $pdf->SetY(95);
    $pdf->SetDrawColorArray($accentColor);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    
    // Résultats académiques
    $pdf->SetY(100);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColorArray($primaryColor);
    $pdf->Cell(190, 10, 'Résultats Académiques', 0, 1, 'L');
    
    // Tableau des notes
    if (!empty($notes_detail)) {
        $pdf->SetY(110);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColorArray($primaryColor);
        $pdf->SetTextColor(255, 255, 255);
        
        // En-tête du tableau
        $pdf->Cell(80, 8, 'Matière', 1, 0, 'C', true);
        $pdf->Cell(27, 8, 'DST (20%)', 1, 0, 'C', true);
        $pdf->Cell(27, 8, 'Recherche (20%)', 1, 0, 'C', true);
        $pdf->Cell(27, 8, 'Session (60%)', 1, 0, 'C', true);
        $pdf->Cell(27, 8, 'Note Finale', 1, 1, 'C', true);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);
        
        $y = $pdf->GetY();
        foreach ($notes_detail as $index => $note) {
            // Alterner les couleurs de fond
            $fill = ($index % 2 == 0) ? false : true;
            $bgColor = $fill ? array(248, 250, 252) : array(255, 255, 255);
            
            $pdf->SetFillColorArray($bgColor);
            
            // Matière
            $pdf->Cell(80, 8, $note['matiere_nom'], 1, 0, 'L', true);
            
            // DST
            $dst = ($note['dst'] !== null) ? number_format($note['dst'], 2) : '-';
            $pdf->Cell(27, 8, $dst, 1, 0, 'C', true);
            
            // Recherche
            $recherche = ($note['recherche'] !== null) ? number_format($note['recherche'], 2) : '-';
            $pdf->Cell(27, 8, $recherche, 1, 0, 'C', true);
            
            // Session
            $session = ($note['session'] !== null) ? number_format($note['session'], 2) : '-';
            $pdf->Cell(27, 8, $session, 1, 0, 'C', true);
            
            // Note finale
            $note_finale = ($note['note_finale'] !== null) ? number_format($note['note_finale'], 2) : '-';
            
            // Choisir la couleur en fonction de la note
            if ($note['note_finale'] !== null) {
                if ($note['note_finale'] >= 16) {
                    $pdf->SetTextColor(40, 167, 69); // Vert
                } elseif ($note['note_finale'] >= 14) {
                    $pdf->SetTextColor(23, 162, 184); // Bleu clair
                } elseif ($note['note_finale'] >= 10) {
                    $pdf->SetTextColor(255, 193, 7); // Jaune
                } else {
                    $pdf->SetTextColor(220, 53, 69); // Rouge
                }
            }
            
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(27, 8, $note_finale, 1, 1, 'C', true);
            
            // Réinitialiser les couleurs pour la prochaine ligne
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('helvetica', '', 9);
            
            $y = $pdf->GetY();
            // Vérifier si on dépasse la page
            if ($y > 250) {
                $pdf->AddPage();
                $pdf->SetY(20);
                $y = $pdf->GetY();
            }
        }
    } else {
        $pdf->SetY(110);
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(190, 10, 'Aucune note disponible pour cette période.', 0, 1, 'C');
    }
    
    // Récapitulatif
    $pdf->SetY($y + 15);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColorArray($primaryColor);
    $pdf->Cell(190, 10, 'Récapitulatif', 0, 1, 'L');
    
    $pdf->SetY($y + 25);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    // Calculer les couleurs pour la moyenne
    $moyenne_couleur = array(0, 0, 0);
    if ($bulletin['moyenne_generale'] >= 16) {
        $moyenne_couleur = array(40, 167, 69);
    } elseif ($bulletin['moyenne_generale'] >= 14) {
        $moyenne_couleur = array(23, 162, 184);
    } elseif ($bulletin['moyenne_generale'] >= 10) {
        $moyenne_couleur = array(255, 193, 7);
    } else {
        $moyenne_couleur = array(220, 53, 69);
    }
    
    // Calculer les couleurs pour la décision
    $decision_couleur = ($bulletin['decision'] === 'admis') ? array(40, 167, 69) : array(220, 53, 69);
    
    // Tableau récapitulatif
    $pdf->SetFillColor(248, 249, 250);
    $pdf->SetDrawColor(206, 212, 218);
    
    $pdf->Cell(47.5, 10, 'Moyenne Générale', 1, 0, 'C', true);
    $pdf->Cell(47.5, 10, 'Rang de classe', 1, 0, 'C', true);
    $pdf->Cell(47.5, 10, 'Décision', 1, 0, 'C', true);
    $pdf->Cell(47.5, 10, 'Mention', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColorArray($moyenne_couleur);
    $pdf->Cell(47.5, 10, number_format($bulletin['moyenne_generale'], 2) . ' / 20', 1, 0, 'C');
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(47.5, 10, $bulletin['rang'] . 'ème sur ' . $bulletin['effectif_classe'], 1, 0, 'C');
    
    $pdf->SetTextColorArray($decision_couleur);
    $pdf->Cell(47.5, 10, ucfirst($bulletin['decision']), 1, 0, 'C');
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(47.5, 10, getMention($bulletin['moyenne_generale']), 1, 1, 'C');
    
    // Appréciation
    if (!empty($bulletin['appreciation'])) {
        $pdf->SetY($pdf->GetY() + 15);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColorArray($primaryColor);
        $pdf->Cell(190, 10, 'Appréciation:', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(190, 8, $bulletin['appreciation'], 0, 'L');
    }
    
    // QR Code (si disponible)
    if (!empty($bulletin['qr_code_final_url'])) {
        $qr_path = ROOT_PATH . $bulletin['qr_code_url'];
        if (file_exists($qr_path)) {
            $pdf->SetY($pdf->GetY() + 10);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetTextColorArray($primaryColor);
            $pdf->Cell(190, 10, 'Validation numérique:', 0, 1, 'C');
            
            // Centrer le QR code
            $qr_x = ($pdf->getPageWidth() - 30) / 2;
            $pdf->Image($qr_path, $qr_x, $pdf->GetY(), 30, 30, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
            
            $pdf->SetY($pdf->GetY() + 32);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->Cell(190, 5, 'Scannez ce QR code pour vérifier l\'authenticité de ce document', 0, 1, 'C');
        }
    }
    
    // Signature
    $pdf->SetY($pdf->GetY() + 20);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(120, $pdf->GetY(), 190, $pdf->GetY());
    
    $pdf->SetY($pdf->GetY() + 5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(190, 5, 'Le Directeur des Affaires Académiques', 0, 1, 'R');
    
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(190, 5, 'ISGI - Institut Supérieur de Gestion Ingénierie', 0, 1, 'R');
    
    // Pied de page
    $pdf->SetY(-20);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(190, 5, 'ISGI - Institut Supérieur de Gestion Ingénierie', 0, 1, 'C');
    $pdf->Cell(190, 5, 'Bulletin généré le ' . date('d/m/Y à H:i') . ' • Réf: ISGI-BLT-' . date('Y') . '-' . str_pad($bulletin['id'], 6, '0', STR_PAD_LEFT), 0, 1, 'C');
    
    // Nom du fichier PDF
    $filename = 'bulletin_' . $bulletin['matricule'] . '_' . $bulletin['annee_academique'] . '_S' . $bulletin['semestre_numero'] . '.pdf';
    
    // Retourner l'objet PDF et le nom de fichier
    return array('pdf' => $pdf, 'filename' => $filename);
}

// Si on demande le téléchargement PDF
if ($download_pdf) {
    $pdf_data = genererPDF($bulletin, $notes_detail);
    
    // Télécharger le PDF
    $pdf_data['pdf']->Output($pdf_data['filename'], 'D');
    exit();
}

// Si on veut juste afficher la page HTML normalement
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulletin de Notes - <?php echo htmlspecialchars($bulletin['etudiant_nom'] . ' ' . $bulletin['etudiant_prenom']); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-blue: #1e3a5f;
            --accent-blue: #2a5c9a;
            --light-bg: #f0f4f8;
            --gold: #d4af37;
            --text-dark: #2c3e50;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
            margin: 0;
        }
        
        .bulletin-card {
            width: 100%;
            max-width: 1100px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
            overflow: hidden;
            border: 1px solid #e1e5eb;
            margin: 0 auto;
        }
        
        .print-controls {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto 30px;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .header-section {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #152642 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .header-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold), #ffd700, var(--gold));
        }
        
        .logo-title-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
        }
        
        .logo-wrapper {
            width: 180px;
            height: 180px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            border: 5px solid var(--gold);
            padding: 15px;
        }
        
        .logo-wrapper img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .school-title {
            text-align: center;
        }
        
        .school-title h1 {
            font-size: 2.4rem;
            font-weight: 800;
            margin-bottom: 8px;
            color: white;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            letter-spacing: 0.5px;
        }
        
        .school-title h2 {
            font-size: 1.6rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.95);
            margin-bottom: 0;
        }
        
        .academic-badges {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 30px;
        }
        
        .badge-item {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            padding: 15px 25px;
            text-align: center;
            min-width: 180px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .badge-label {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .badge-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: white;
        }
        
        .content-section {
            padding: 40px;
        }
        
        .student-info-card {
            background: linear-gradient(135deg, #f8fafc 0%, #e8f1fa 100%);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 40px;
            border-left: 6px solid var(--accent-blue);
            box-shadow: 0 8px 25px rgba(30, 58, 95, 0.08);
        }
        
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .info-box {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
        }
        
        .info-label {
            font-size: 0.85rem;
            color: var(--primary-blue);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .grades-section {
            margin: 40px 0;
        }
        
        .section-title {
            color: var(--primary-blue);
            font-weight: 700;
            font-size: 1.4rem;
            margin-bottom: 25px;
            padding-bottom: 12px;
            border-bottom: 3px solid var(--accent-blue);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .grades-table {
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        
        .grades-table thead th {
            background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
            color: white;
            font-weight: 600;
            padding: 18px 15px;
            text-align: center;
            border: none;
            font-size: 1rem;
        }
        
        .grades-table tbody td {
            padding: 16px 15px;
            text-align: center;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            font-size: 1rem;
        }
        
        .grades-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }
        
        .grades-table tbody tr:hover {
            background-color: rgba(42, 92, 154, 0.05);
            transition: background-color 0.3s;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 25px;
            margin: 40px 0;
        }
        
        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border-top: 5px solid var(--accent-blue);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        }
        
        .summary-value {
            font-size: 2.8rem;
            font-weight: 800;
            margin: 15px 0;
            line-height: 1;
            color: var(--primary-blue);
        }
        
        .qr-section {
            text-align: center;
            padding: 40px;
            background: linear-gradient(135deg, #f8fafc 0%, #e8f1fa 100%);
            border-radius: 15px;
            margin: 40px 0;
            border: 2px dashed var(--accent-blue);
        }
        
        .qr-box {
            display: inline-block;
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .signature-section {
            margin-top: 50px;
            padding-top: 40px;
            border-top: 2px solid #e9ecef;
        }
        
        .signature-box {
            text-align: right;
            padding: 30px;
        }
        
        .signature-line {
            width: 350px;
            height: 1px;
            background: var(--text-dark);
            margin: 50px 0 10px auto;
        }
        
        .btn-print {
            background: linear-gradient(135deg, var(--primary-blue), var(--accent-blue));
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .btn-print:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(30, 58, 95, 0.2);
            color: white;
        }
        
        .btn-pdf {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s;
            font-size: 1rem;
        }
        
        .btn-pdf:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(220, 53, 69, 0.2);
            color: white;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .bulletin-card {
                border-radius: 15px;
            }
            
            .header-section {
                padding: 30px 20px;
            }
            
            .school-title h1 {
                font-size: 1.8rem;
            }
            
            .school-title h2 {
                font-size: 1.2rem;
            }
            
            .logo-wrapper {
                width: 140px;
                height: 140px;
            }
            
            .content-section {
                padding: 25px;
            }
            
            .print-controls {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-print, .btn-pdf {
                width: 100%;
                justify-content: center;
            }
            
            .signature-line {
                width: 250px;
            }
        }
        
        @media print {
            body { 
                padding: 0;
                background: white;
            }
            
            .print-controls {
                display: none;
            }
            
            .bulletin-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <!-- CONTROLES D'IMPRESSION -->
    <div class="print-controls">
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Imprimer ce bulletin
        </button>
        <a href="?bulletin_id=<?php echo $bulletin_id; ?>&download_pdf=1" class="btn-pdf">
            <i class="fas fa-file-pdf"></i> Télécharger en PDF
        </a>
        <a href="bulletins.php" class="btn-print">
            <i class="fas fa-arrow-left"></i> Retour aux bulletins
        </a>
    </div>
    
    <!-- BULLETIN PRINCIPAL -->
    <div class="bulletin-card">
        <!-- EN-TÊTE -->
        <div class="header-section">
            <div class="logo-title-container">
                <!-- LOGO DE L'ÉCOLE -->
                <div class="logo-wrapper">
                    <img src="../../image/logo isgi.jpg" alt="Logo ISGI" 
                         onerror="this.onerror=null; this.style.display='none'; this.parentNode.innerHTML='<div style=\'font-size:2.5rem;color:#1e3a5f;font-weight:bold;\'>ISGI</div>';">
                </div>
                
                <div class="school-title">
                    <h1>INSTITUT SUPÉRIEUR DE GESTION INGENIERIE</h1>
                    <h2>BULLETIN DE NOTES OFFICIEL</h2>
                </div>
            </div>
            
            <div class="academic-badges">
                <div class="badge-item">
                    <div class="badge-label">Année académique</div>
                    <div class="badge-value"><?php echo htmlspecialchars($bulletin['annee_academique']); ?></div>
                </div>
                <div class="badge-item">
                    <div class="badge-label">Semestre</div>
                    <div class="badge-value"><?php echo htmlspecialchars($bulletin['semestre_numero']); ?></div>
                </div>
                <div class="badge-item">
                    <div class="badge-label">Date d'édition</div>
                    <div class="badge-value"><?php echo date('d/m/Y', strtotime($bulletin['date_edition'])); ?></div>
                </div>
                <div class="badge-item">
                    <div class="badge-label">N° Bulletin</div>
                    <div class="badge-value"><?php echo str_pad($bulletin['id'], 6, '0', STR_PAD_LEFT); ?></div>
                </div>
            </div>
        </div>
        
        <!-- CONTENU PRINCIPAL -->
        <div class="content-section">
            <!-- Informations étudiant -->
            <div class="student-info-card">
                <h3 class="section-title">
                    <i class="fas fa-user-graduate"></i> Informations de l'Étudiant
                </h3>
                
                <div class="student-grid">
                    <div class="info-box">
                        <div class="info-label">
                            <i class="fas fa-id-card"></i> Nom complet
                        </div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($bulletin['etudiant_nom'] . ' ' . $bulletin['etudiant_prenom']); ?>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <div class="info-label">
                            <i class="fas fa-hashtag"></i> Matricule
                        </div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($bulletin['matricule']); ?>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <div class="info-label">
                            <i class="fas fa-building"></i> Classe
                        </div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($bulletin['classe_nom']); ?>
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <div class="info-label">
                            <i class="fas fa-calendar-alt"></i> Date d'édition
                        </div>
                        <div class="info-value">
                            <?php echo date('d/m/Y à H:i', strtotime($bulletin['date_edition'])); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tableau des notes -->
            <div class="grades-section">
                <h3 class="section-title">
                    <i class="fas fa-chart-line"></i> Résultats Académiques
                </h3>
                
                <?php if (!empty($notes_detail)): ?>
                <div class="table-responsive">
                    <table class="table grades-table">
                        <thead>
                            <tr>
                                <th>Matière</th>
                                <th>DST (20%)</th>
                                <th>Recherche (20%)</th>
                                <th>Session (60%)</th>
                                <th>Note Finale</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notes_detail as $note): ?>
                            <tr>
                                <td class="text-start fw-bold">
                                    <?php echo htmlspecialchars($note['matiere_nom']); ?>
                                </td>
                                <td>
                                    <?php echo $note['dst'] !== null ? number_format($note['dst'], 2) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td>
                                    <?php echo $note['recherche'] !== null ? number_format($note['recherche'], 2) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td>
                                    <?php echo $note['session'] !== null ? number_format($note['session'], 2) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td class="fw-bold" style="color: <?php 
                                    if ($note['note_finale'] >= 16) echo '#28a745';
                                    elseif ($note['note_finale'] >= 14) echo '#17a2b8';
                                    elseif ($note['note_finale'] >= 10) echo '#ffc107';
                                    else echo '#dc3545';
                                ?>;">
                                    <?php echo $note['note_finale'] !== null ? number_format($note['note_finale'], 2) : '<span class="text-muted">-</span>'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="alert alert-warning text-center py-4">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                    <h5 class="mt-3">Aucune note disponible</h5>
                    <p class="mb-0">Cet étudiant n'a pas de notes enregistrées pour cette période.</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Récapitulatif -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="info-label">Moyenne Générale</div>
                    <div class="summary-value" style="color: <?php 
                        if ($bulletin['moyenne_generale'] >= 16) echo '#28a745';
                        elseif ($bulletin['moyenne_generale'] >= 14) echo '#17a2b8';
                        elseif ($bulletin['moyenne_generale'] >= 10) echo '#ffc107';
                        else echo '#dc3545';
                    ?>;">
                        <?php echo number_format($bulletin['moyenne_generale'], 2); ?>
                    </div>
                    <div class="text-muted">/ 20 points</div>
                </div>
                
                <div class="summary-card">
                    <div class="info-label">Rang de classe</div>
                    <div class="summary-value">
                        <?php echo $bulletin['rang']; ?>ème
                    </div>
                    <div class="text-muted">Sur <?php echo $bulletin['effectif_classe']; ?> étudiants</div>
                </div>
                
                <div class="summary-card">
                    <div class="info-label">Décision</div>
                    <div class="summary-value" style="color: <?php echo $bulletin['decision'] === 'admis' ? '#28a745' : '#dc3545'; ?>;">
                        <?php echo ucfirst($bulletin['decision']); ?>
                    </div>
                    <div class="text-muted">
                        <?php echo getMention($bulletin['moyenne_generale']); ?>
                    </div>
                </div>
                
                <div class="summary-card">
                    <div class="info-label">Mention</div>
                    <div class="summary-value">
                        <?php echo getMention($bulletin['moyenne_generale']); ?>
                    </div>
                    <div class="text-muted">Appréciation</div>
                </div>
            </div>
            
            <!-- Appréciation -->
            <?php if (!empty($bulletin['appreciation'])): ?>
            <div class="alert alert-info" style="border-radius: 15px; padding: 25px; border-left: 6px solid #17a2b8;">
                <h5 class="mb-3">
                    <i class="fas fa-comment-dots me-2"></i> Appréciation
                </h5>
                <p class="mb-0" style="font-size: 1.1rem; line-height: 1.6;">
                    <?php echo htmlspecialchars($bulletin['appreciation']); ?>
                </p>
            </div>
            <?php endif; ?>
            
            <!-- QR Code -->
            <?php if (!empty($bulletin['qr_code_final_url'])): ?>
            <div class="qr-section">
                <h5 class="section-title">
                    <i class="fas fa-qrcode"></i> Validation Numérique
                </h5>
                
                <div class="qr-box">
                    <img src="<?php echo htmlspecialchars($bulletin['qr_code_final_url']); ?>" 
                         alt="QR Code de validation" 
                         width="200" 
                         height="200"
                         onerror="this.onerror=null; this.src=''; this.parentNode.innerHTML='<div style=\'width:200px;height:200px;display:flex;align-items:center;justify-content:center;color:#dc3545;\'><i class=\"fas fa-exclamation-triangle fa-3x\"></i></div>';">
                </div>
                
                <p class="mb-2 fw-bold">
                    Code d'authentification unique
                </p>
                <p class="text-muted mb-0">
                    Scannez ce QR code pour vérifier l'authenticité de ce document
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Signature -->
            <div class="signature-section">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <p class="mb-0 mt-2 fw-bold">
                        Le Directeur des Affaires Académiques
                    </p>
                    <p class="text-muted">
                        ISGI - Institut Supérieur de Gestion Ingénierie
                    </p>
                </div>
            </div>
            
            <!-- Pied de page -->
            <div class="text-center mt-5 pt-3 border-top">
                <p class="text-muted small mb-1">
                    ISGI - Institut Supérieur de Gestion Ingénierie
                </p>
                <p class="text-muted small mb-0">
                    Bulletin généré le <?php echo date('d/m/Y à H:i'); ?> • 
                    Réf: ISGI-BLT-<?php echo date('Y'); ?>-<?php echo str_pad($bulletin['id'], 6, '0', STR_PAD_LEFT); ?>
                </p>
            </div>
        </div>
    </div>
    
    <script>
    // Auto-impression
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('print') === '1') {
        setTimeout(() => window.print(), 500);
    }
    </script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>