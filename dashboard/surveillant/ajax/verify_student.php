<?php
// dashboard/surveillant/ajax/verify_student_complete.php

session_start();
require_once '../../../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $pdo = Database::getConnection();
    
    // Extraire le matricule du QR code
    $matricule = extractMatriculeFromQR($data['qr_data']);
    
    if (empty($matricule)) {
        echo json_encode([
            'success' => false,
            'message' => 'Matricule non trouvé dans le QR code'
        ]);
        exit();
    }
    
    // VÉRIFICATION PRINCIPALE DANS LA TABLE ETUDIANT
    $student = getStudentByMatricule($pdo, $matricule);
    
    if (!$student) {
        // Si pas trouvé par matricule exact, chercher par similarité
        $similarStudents = findSimilarStudents($pdo, $matricule);
        
        if (!empty($similarStudents)) {
            echo json_encode([
                'success' => false,
                'message' => 'Étudiant non trouvé, mais des étudiants similaires existent',
                'similar_students' => $similarStudents,
                'details' => 'Recherche de similarité effectuée'
            ]);
            exit();
        }
        
        echo json_encode([
            'success' => false,
            'message' => 'Étudiant non inscrit dans la base de données',
            'details' => 'Vérification dans table etudiant: NON TROUVÉ'
        ]);
        exit();
    }
    
    // Vérifier le statut de l'étudiant
    if ($student['statut'] !== 'actif') {
        echo json_encode([
            'success' => false,
            'message' => 'Étudiant non actif',
            'student' => $student,
            'details' => 'Statut: ' . $student['statut']
        ]);
        exit();
    }
    
    // Vérifier si l'étudiant a déjà été scanné aujourd'hui pour le même type
    $alreadyScanned = checkAlreadyScannedToday($pdo, $student['id']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Étudiant vérifié avec succès',
        'student' => formatStudentData($student),
        'details' => [
            'verification_source' => 'table_etudiant',
            'student_found' => true,
            'student_active' => true,
            'already_scanned_today' => $alreadyScanned,
            'verification_timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur: ' . $e->getMessage()
    ]);
}

// Fonctions utilitaires
function extractMatriculeFromQR($qrData) {
    if (is_array($qrData)) {
        return $qrData['matricule'] ?? $qrData['MATRICULE'] ?? '';
    }
    
    if (is_string($qrData)) {
        // Chercher le matricule dans la chaîne
        if (preg_match('/MATRICULE[:\s]*([A-Z0-9\-]+)/i', $qrData, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/(ISGI|ETU|STD)[\-_\s]*[0-9]+/i', $qrData, $matches)) {
            return $matches[0];
        }
        
        // Si c'est juste le matricule
        if (preg_match('/^[A-Z0-9\-]{5,20}$/', $qrData)) {
            return $qrData;
        }
    }
    
    return '';
}

function getStudentByMatricule($pdo, $matricule) {
    $sql = "SELECT 
                e.*, 
                c.nom as classe_nom,
                c.niveau as classe_niveau,
                u.nom as surveillant_nom
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            LEFT JOIN utilisateurs u ON e.surveillant_id = u.id
            WHERE e.matricule = :matricule
            AND e.deleted_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':matricule' => $matricule]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function findSimilarStudents($pdo, $searchTerm) {
    $sql = "SELECT 
                id, matricule, nom, prenom, classe_id,
                MATCH(nom, prenom, matricule) AGAINST(:search IN BOOLEAN MODE) as relevance
            FROM etudiants 
            WHERE (matricule LIKE :like_search 
                   OR nom LIKE :like_search 
                   OR prenom LIKE :like_search)
            AND statut = 'actif'
            AND deleted_at IS NULL
            ORDER BY relevance DESC
            LIMIT 5";
    
    $stmt = $pdo->prepare($sql);
    $likeSearch = "%$searchTerm%";
    $stmt->execute([
        ':search' => $searchTerm . '*',
        ':like_search' => $likeSearch
    ]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function checkAlreadyScannedToday($pdo, $studentId) {
    $today = date('Y-m-d');
    
    $sql = "SELECT COUNT(*) as count 
            FROM presences 
            WHERE etudiant_id = :student_id 
            AND DATE(date_heure) = :today";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':student_id' => $studentId,
        ':today' => $today
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'] > 0;
}

function formatStudentData($student) {
    return [
        'id' => $student['id'],
        'matricule' => $student['matricule'],
        'nom' => $student['nom'],
        'prenom' => $student['prenom'],
        'classe' => $student['classe_nom'] ?? '',
        'classe_niveau' => $student['classe_niveau'] ?? '',
        'date_naissance' => $student['date_naissance'] ?? '',
        'lieu_naissance' => $student['lieu_naissance'] ?? '',
        'telephone' => $student['telephone'] ?? '',
        'email' => $student['email'] ?? '',
        'adresse' => $student['adresse'] ?? '',
        'photo' => $student['photo'] ?? '',
        'statut' => $student['statut'],
        'date_inscription' => $student['date_inscription'] ?? '',
        'surveillant' => $student['surveillant_nom'] ?? ''
    ];
}