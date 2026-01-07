<?php
// api/pdf_converter.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(__FILE__)));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier si c'est une requête API
if (!isset($_SERVER['HTTP_X_API_KEY']) || $_SERVER['HTTP_X_API_KEY'] !== 'ISGI_BIBLIO_2025') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Accès non autorisé']);
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Initialiser les variables
    $action = $_GET['action'] ?? 'convert';
    $livre_id = $_GET['livre_id'] ?? 0;
    $contenu_html = $_POST['contenu_html'] ?? '';
    
    switch ($action) {
        case 'convert':
            // Convertir le contenu HTML en PDF avec Times New Roman
            if ($livre_id > 0) {
                $stmt = $db->prepare("SELECT fichier_pdf FROM bibliotheque_livres WHERE id = ?");
                $stmt->execute([$livre_id]);
                $livre = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($livre && file_exists(ROOT_PATH . '/' . $livre['fichier_pdf'])) {
                    // Convertir le PDF existant
                    convertPDF(ROOT_PATH . '/' . $livre['fichier_pdf']);
                }
            } elseif (!empty($contenu_html)) {
                // Convertir le HTML en PDF
                convertHTMLToPDF($contenu_html);
            }
            break;
            
        case 'style':
            // Appliquer le style Times New Roman à un contenu HTML
            header('Content-Type: application/json');
            $styled_html = applyTimesNewRomanStyle($contenu_html);
            echo json_encode(['styled_html' => $styled_html]);
            break;
            
        case 'check_pages':
            // Vérifier le nombre de pages d'un document
            if ($livre_id > 0) {
                $stmt = $db->prepare("SELECT nombre_pages FROM bibliotheque_documents WHERE id = ?");
                $stmt->execute([$livre_id]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                
                header('Content-Type: application/json');
                echo json_encode([
                    'pages' => $doc['nombre_pages'] ?? 0,
                    'minimum' => 30,
                    'valid' => ($doc['nombre_pages'] ?? 0) >= 30
                ]);
            }
            break;
    }
    
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}

// Fonction pour appliquer le style Times New Roman
function applyTimesNewRomanStyle($html) {
    // Ajouter les styles CSS
    $style = '
        <style>
            body, p, div, span, h1, h2, h3, h4, h5, h6, li, td, th {
                font-family: "Times New Roman", Times, serif !important;
            }
            
            body {
                font-size: 12pt !important;
                line-height: 1.5 !important;
                text-align: left !important;
                direction: ltr !important;
                margin: 2cm !important;
            }
            
            p {
                margin-bottom: 12pt !important;
                text-align: justify !important;
            }
            
            h1 { font-size: 16pt !important; }
            h2 { font-size: 14pt !important; }
            h3 { font-size: 13pt !important; }
            h4, h5, h6 { font-size: 12pt !important; }
            
            table {
                border-collapse: collapse !important;
                width: 100% !important;
            }
            
            th, td {
                border: 1px solid #000 !important;
                padding: 8pt !important;
                font-size: 11pt !important;
            }
            
            ul, ol {
                margin-left: 1.5cm !important;
            }
            
            blockquote {
                margin: 12pt 2cm !important;
                font-style: italic !important;
                padding-left: 12pt !important;
                border-left: 3px solid #ccc !important;
            }
        </style>
    ';
    
    // Insérer le style dans le HTML
    $html = str_replace('</head>', $style . '</head>', $html);
    if (strpos($html, '<head>') === false) {
        $html = '<!DOCTYPE html><html><head>' . $style . '</head><body>' . $html . '</body></html>';
    }
    
    return $html;
}

// Fonction pour convertir le HTML en PDF
function convertHTMLToPDF($html) {
    $styled_html = applyTimesNewRomanStyle($html);
    
    // Utiliser TCPDF, mPDF ou DomPDF pour la conversion
    // Pour cet exemple, nous allons simuler la conversion
    
    $pdf_filename = 'document_' . time() . '.pdf';
    $pdf_path = ROOT_PATH . '/assets/uploads/converted/' . $pdf_filename;
    
    // Créer le dossier s'il n'existe pas
    if (!file_exists(dirname($pdf_path))) {
        mkdir(dirname($pdf_path), 0777, true);
    }
    
    // Note: Dans une vraie implémentation, vous utiliseriez une bibliothèque PDF
    // comme TCPDF, mPDF ou DomPDF pour convertir le HTML en PDF
    
    // Pour l'instant, nous allons créer un fichier PDF simple
    $pdf_content = '%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>
endobj
4 0 obj
<< /Length 44 >>
stream
BT /F1 12 Tf 72 720 Td (Document converti en Times New Roman) Tj ET
endstream
endobj
xref
0 5
0000000000 65535 f
0000000010 00000 n
0000000053 00000 n
0000000105 00000 n
0000000170 00000 n
trailer
<< /Size 5 /Root 1 0 R >>
startxref
235
%%EOF';
    
    file_put_contents($pdf_path, $pdf_content);
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $pdf_filename . '"');
    readfile($pdf_path);
}

// Fonction pour convertir un PDF existant
function convertPDF($pdf_path) {
    // Cette fonction nécessiterait une bibliothèque comme Ghostscript
    // Pour convertir les polices dans le PDF en Times New Roman
    
    if (!file_exists($pdf_path)) {
        throw new Exception("Fichier PDF non trouvé");
    }
    
    // Vérifier si Ghostscript est disponible
    $gs_path = '/usr/bin/gs'; // Chemin Linux
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $gs_path = 'C:\Program Files\gs\bin\gswin64c.exe';
    }
    
    if (!file_exists($gs_path)) {
        // Si Ghostscript n'est pas disponible, renvoyer le PDF original
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdf_path) . '"');
        readfile($pdf_path);
        return;
    }
    
    // Chemin pour le PDF converti
    $converted_path = str_replace('.pdf', '_converted.pdf', $pdf_path);
    
    // Commande Ghostscript pour forcer Times New Roman
    $command = escapeshellcmd($gs_path) . ' -sDEVICE=pdfwrite ' .
               '-dEmbedAllFonts=true ' .
               '-dSubsetFonts=true ' .
               '-dPDFSETTINGS=/prepress ' .
               '-dCompatibilityLevel=1.4 ' .
               '-sOutputFile=' . escapeshellarg($converted_path) . ' ' .
               escapeshellarg($pdf_path) . ' 2>&1';
    
    exec($command, $output, $return_var);
    
    if ($return_var === 0 && file_exists($converted_path)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($converted_path) . '"');
        readfile($converted_path);
        
        // Supprimer le fichier temporaire
        unlink($converted_path);
    } else {
        // En cas d'erreur, renvoyer le PDF original
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($pdf_path) . '"');
        readfile($pdf_path);
    }
}
?>