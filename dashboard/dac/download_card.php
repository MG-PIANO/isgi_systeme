<?php
// dashboard/dac/download_card.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

$etudiant_id = $_GET['id'] ?? null;

if ($etudiant_id) {
    try {
        $db = Database::getInstance()->getConnection();
        $site_id = $_SESSION['site_id'] ?? null;
        
        $query = "SELECT e.*, s.nom as site_nom, f.nom as filiere_nom, n.libelle as niveau_libelle 
                  FROM etudiants e
                  LEFT JOIN sites s ON e.site_id = s.id
                  LEFT JOIN inscriptions i ON e.id = i.etudiant_id
                  LEFT JOIN filieres f ON i.filiere_id = f.id
                  LEFT JOIN niveaux n ON i.niveau = n.code
                  WHERE e.id = ? AND e.site_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$etudiant_id, $site_id]);
        $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($etudiant) {
            // Vérifier si FPDF existe
            $fpdf_path = ROOT_PATH . '/vendor/fpdf/fpdf.php';
            
            if (file_exists($fpdf_path)) {
                require_once $fpdf_path;
                
                class PDF extends FPDF {
                    function Header() {
                        $this->SetFont('Arial', 'B', 16);
                        $this->Cell(0, 10, 'ISGI - Carte Etudiant', 0, 1, 'C');
                        $this->Ln(5);
                    }
                    
                    function Footer() {
                        $this->SetY(-15);
                        $this->SetFont('Arial', 'I', 8);
                        $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
                    }
                }
                
                $pdf = new PDF();
                $pdf->AddPage();
                
                // Informations de l'étudiant
                $pdf->SetFont('Arial', 'B', 12);
                $pdf->Cell(0, 10, 'Informations de l\'etudiant', 0, 1);
                $pdf->Ln(5);
                
                $pdf->SetFont('Arial', '', 10);
                $pdf->Cell(50, 8, 'Matricule:', 0, 0);
                $pdf->Cell(0, 8, $etudiant['matricule'], 0, 1);
                
                $pdf->Cell(50, 8, 'Nom complet:', 0, 0);
                $pdf->Cell(0, 8, $etudiant['prenom'] . ' ' . $etudiant['nom'], 0, 1);
                
                $pdf->Cell(50, 8, 'Filiere:', 0, 0);
                $pdf->Cell(0, 8, $etudiant['filiere_nom'] ?? 'Non specifie', 0, 1);
                
                $pdf->Cell(50, 8, 'Niveau:', 0, 0);
                $pdf->Cell(0, 8, $etudiant['niveau_libelle'] ?? 'N/A', 0, 1);
                
                $pdf->Cell(50, 8, 'Date de naissance:', 0, 0);
                $pdf->Cell(0, 8, date('d/m/Y', strtotime($etudiant['date_naissance'])), 0, 1);
                
                $pdf->Ln(10);
                
                // Code QR
                if (!empty($etudiant['qr_code_data'])) {
                    $pdf->SetFont('Arial', 'B', 12);
                    $pdf->Cell(0, 10, 'Code QR de verification:', 0, 1);
                    
                    // Vérifier si le fichier QR code existe
                    $qr_file = ROOT_PATH . '/uploads/qrcodes/qrcode_' . $etudiant['matricule'] . '.png';
                    if (file_exists($qr_file)) {
                        $pdf->Image($qr_file, 80, $pdf->GetY(), 50, 0, 'PNG');
                    }
                }
                
                $pdf->Ln(20);
                
                // Signature
                $pdf->Cell(0, 10, 'Fait a Brazzaville, le ' . date('d/m/Y'), 0, 1, 'C');
                $pdf->Ln(10);
                $pdf->Cell(0, 10, 'Le Directeur des Affaires Academiques', 0, 1, 'C');
                $pdf->Cell(0, 10, 'Signature', 0, 1, 'C');
                
                // Nom du fichier
                $filename = 'carte_etudiant_' . $etudiant['matricule'] . '.pdf';
                
                // Envoyer le PDF
                $pdf->Output('D', $filename);
                exit();
                
            } else {
                // FPDF n'existe pas, créer un simple HTML
                header('Content-Type: text/html');
                echo '<!DOCTYPE html>
                <html>
                <head>
                    <title>Carte Étudiant - ' . $etudiant['prenom'] . ' ' . $etudiant['nom'] . '</title>
                    <style>
                        body { font-family: Arial; margin: 40px; }
                        .card { border: 2px solid #007bff; padding: 20px; max-width: 600px; margin: auto; }
                        h2 { color: #007bff; }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <h2>Carte Étudiant ISGI</h2>
                        <p><strong>Matricule:</strong> ' . $etudiant['matricule'] . '</p>
                        <p><strong>Nom complet:</strong> ' . $etudiant['prenom'] . ' ' . $etudiant['nom'] . '</p>
                        <p><strong>Filière:</strong> ' . ($etudiant['filiere_nom'] ?? 'Non spécifié') . '</p>
                        <p><strong>Niveau:</strong> ' . ($etudiant['niveau_libelle'] ?? 'N/A') . '</p>
                        <p><strong>Date de naissance:</strong> ' . date('d/m/Y', strtotime($etudiant['date_naissance'])) . '</p>
                        <p><strong>Date d\'émission:</strong> ' . date('d/m/Y') . '</p>
                        <br><br>
                        <p>Signature: _______________________</p>
                    </div>
                    <script>window.print();</script>
                </body>
                </html>';
                exit();
            }
        }
    } catch (Exception $e) {
        header('Location: cartes_etudiant.php?error=' . urlencode($e->getMessage()));
        exit();
    }
}

header('Location: cartes_etudiant.php?error=Etudiant non trouve');
exit();