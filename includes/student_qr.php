<?php

function isgi_student_qr_payload(array $student): string
{
    $matricule = strtoupper(trim((string)($student['matricule'] ?? '')));
    $nom = strtoupper(trim((string)($student['nom'] ?? '')));
    $prenom = trim((string)($student['prenom'] ?? ''));

    if ($matricule === '' || ($nom === '' && $prenom === '')) {
        throw new InvalidArgumentException('Un matricule et un nom ou prénom sont nécessaires pour générer le QR.');
    }

    $payload = json_encode([
        'type' => 'ISGI_STUDENT',
        'version' => 1,
        'matricule' => $matricule,
        'nom' => $nom,
        'prenom' => $prenom
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Les informations de l’étudiant ne peuvent pas être encodées en QR.');
    }

    return $payload;
}

function isgi_student_qr_card(array $student): string
{
    $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
    $barcodeLibrary = $rootPath . '/vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
    if (!is_file($barcodeLibrary)) {
        throw new RuntimeException('La bibliothèque de génération QR TCPDF est introuvable.');
    }
    require_once $barcodeLibrary;

    $payload = isgi_student_qr_payload($student);
    $barcode = new TCPDF2DBarcode($payload, 'QRCODE,M');
    $studentName = trim((string)($student['nom'] ?? '') . ' ' . (string)($student['prenom'] ?? ''));

    return '<div class="card mt-4"><div class="card-body text-center">'
        . '<h5 class="card-title">Code QR d’identification</h5>'
        . '<div class="d-inline-block bg-white p-2">'
        . $barcode->getBarcodeHTML(3, 3, '#003087')
        . '</div><p class="mb-1 mt-2"><strong>'
        . htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8')
        . '</strong></p><code>'
        . htmlspecialchars(strtoupper(trim((string)($student['matricule'] ?? ''))), ENT_QUOTES, 'UTF-8')
        . '</code></div></div>';
}
