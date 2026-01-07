<?php
// dashboard/dac/batch_generate.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

@include_once ROOT_PATH . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    $site_id = $_SESSION['site_id'] ?? null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['etudiant_ids'])) {
        $etudiant_ids = $_POST['etudiant_ids'];
        $print_format = $_POST['print_format'] ?? 'a4';
        
        if (!is_array($etudiant_ids) || empty($etudiant_ids)) {
            header('Location: cartes_etudiant.php?error=Aucun étudiant sélectionné');
            exit();
        }
        
        // Récupérer les étudiants
        $placeholders = str_repeat('?,', count($etudiant_ids) - 1) . '?';
        $query = "SELECT e.*, s.nom as site_nom, f.nom as filiere_nom, n.libelle as niveau_libelle
                  FROM etudiants e
                  LEFT JOIN sites s ON e.site_id = s.id
                  LEFT JOIN inscriptions i ON e.id = i.etudiant_id
                  LEFT JOIN filieres f ON i.filiere_id = f.id
                  LEFT JOIN niveaux n ON i.niveau = n.code
                  WHERE e.id IN ($placeholders) AND e.site_id = ?";
        
        $params = array_merge($etudiant_ids, [$site_id]);
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Générer les QR codes si nécessaire
        require_once ROOT_PATH . '/includes/phpqrcode/qrlib.php';
        
        foreach ($etudiants as $etudiant) {
            if (empty($etudiant['qr_code_data'])) {
                $qr_data = "ETUDIANT:" . $etudiant['matricule'] . "|NOM:" . $etudiant['nom'] . "|PRENOM:" . $etudiant['prenom'] . "|SITE:" . $site_id;
                $qr_filename = 'qrcode_' . $etudiant['matricule'] . '.png';
                $qr_path = ROOT_PATH . '/uploads/qrcodes/' . $qr_filename;
                
                if (!file_exists(dirname($qr_path))) {
                    mkdir(dirname($qr_path), 0777, true);
                }
                
                QRcode::png($qr_data, $qr_path, QR_ECLEVEL_L, 10);
                
                // Mettre à jour l'étudiant
                $update_query = "UPDATE etudiants SET qr_code_data = ? WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([$qr_data, $etudiant['id']]);
            }
        }
        
        // Générer le PDF des cartes
        require_once ROOT_PATH . '/includes/fpdf/fpdf.php';
        
        class PDF extends FPDF {
            private $cards_per_page;
            
            function __construct($format = 'a4') {
                parent::__construct('P', 'mm', strtoupper($format));
                $this->cards_per_page = $format === 'a4_8' ? 8 : 6;
            }
            
            function Header() {
                // Pas d'en-tête pour les cartes
            }
            
            function Footer() {
                // Pas de pied de page pour les cartes
            }
            
            function AddCard($etudiant, $index) {
                $margin = 10;
                $card_width = ($this->GetPageWidth() - ($margin * 3)) / 2;
                $card_height = ($this->GetPageHeight() - ($margin * 3)) / 3;
                
                if ($this->cards_per_page == 8) {
                    $card_width = ($this->GetPageWidth() - ($margin * 5)) / 4;
                    $card_height = ($this->GetPageHeight() - ($margin * 3)) / 4;
                }
                
                // Positionner la carte
                $col = $index % ($this->cards_per_page == 8 ? 4 : 2);
                $row = floor($index / ($this->cards_per_page == 8 ? 4 : 2));
                
                $x = $margin + ($col * ($card_width + $margin));
                $y = $margin + ($row * ($card_height + $margin));
                
                // Bordure de la carte
                $this->SetXY($x, $y);
                $this->SetDrawColor(0, 123, 255);
                $this->SetLineWidth(0.5);
                $this->Rect($x, $y, $card_width, $card_height);
                
                // Logo ISGI
                $this->SetFont('Arial', 'B', 8);
                $this->SetXY($x + 5, $y + 5);
                $this->Cell($card_width - 10, 5, 'ISGI', 0, 1, 'C');
                
                // Année académique
                $this->SetFont('Arial', '', 6);
                $this->SetX($x + 5);
                $this->Cell($card_width - 10, 3, date('Y') . '/' . (date('Y') + 1), 0, 1, 'C');
                
                // Photo (placeholder)
                $this->SetXY($x + 15, $y + 15);
                $this->SetFillColor(240, 240, 240);
                $this->Rect($this->GetX(), $this->GetY(), 20, 25, 'F');
                $this->SetFont('Arial', 'I', 6);
                $this->SetXY($x + 15, $y + 40);
                $this->Cell(20, 3, 'Photo', 0, 1, 'C');
                
                // Informations étudiant
                $this->SetFont('Arial', 'B', 7);
                $this->SetXY($x + 40, $y + 15);
                $this->Cell($card_width - 45, 4, $etudiant['prenom'] . ' ' . $etudiant['nom'], 0, 1);
                
                $this->SetFont('Arial', '', 6);
                $this->SetX($x + 40);
                $this->Cell($card_width - 45, 3, 'Matricule: ' . $etudiant['matricule'], 0, 1);
                
                $this->SetX($x + 40);
                $this->Cell($card_width - 45, 3, 'Filiere: ' . ($etudiant['filiere_nom'] ?? 'Non specifie'), 0, 1);
                
                $this->SetX($x + 40);
                $this->Cell($card_width - 45, 3, 'Niveau: ' . ($etudiant['niveau_libelle'] ?? 'N/A'), 0, 1);
                
                $this->SetX($x + 40);
                $this->Cell($card_width - 45, 3, 'Date emission: ' . date('d/m/Y'), 0, 1);
                
                // QR Code placeholder
                $this->SetXY($x + ($card_width - 25), $y + ($card_height - 25));
                $this->SetFillColor(200, 200, 200);
                $this->Rect($this->GetX(), $this->GetY(), 20, 20, 'F');
                $this->SetFont('Arial', 'I', 5);
                $this->SetXY($x + ($card_width - 25), $y + ($card_height - 5));
                $this->Cell(20, 3, 'QR Code', 0, 1, 'C');
                
                // Signature
                $this->SetFont('Arial', 'I', 5);
                $this->SetXY($x + 5, $y + ($card_height - 10));
                $this->Cell($card_width - 10, 3, 'Signature du Directeur', 0, 1, 'C');
            }
        }
        
        $pdf = new PDF($print_format);
        $pdf->SetAutoPageBreak(false);
        
        foreach ($etudiants as $index => $etudiant) {
            if ($index % $pdf->cards_per_page == 0 && $index > 0) {
                $pdf->AddPage();
            }
            
            // Ajouter une nouvelle page si nécessaire
            if ($index % $pdf->cards_per_page == 0) {
                if ($index > 0) {
                    $pdf->AddPage();
                }
                // Première page
            }
            
            $pdf->AddCard($etudiant, $index % $pdf->cards_per_page);
        }
        
        $filename = 'cartes_etudiants_' . date('Ymd_His') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $pdf->Output('D');
        exit();
    }
    
    header('Location: cartes_etudiant.php?error=Requete invalide');
    exit();
    
} catch (Exception $e) {
    header('Location: cartes_etudiants.php?error=' . urlencode($e->getMessage()));
    exit();
}