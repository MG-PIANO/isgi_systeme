<?php
// dashboard/surveillant/ajax/process_manual_entry.php

// Chemin racine
define('ROOT_PATH', dirname(dirname(dirname(dirname(__FILE__)))));

// Activer les erreurs pour le débogage
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier l'authentification et le rôle
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Accès non autorisé. Veuillez vous reconnecter.',
        'error_code' => 'AUTH_REQUIRED'
    ]);
    exit();
}

// Vérifier la méthode HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Méthode non autorisée. Utilisez POST.',
        'error_code' => 'INVALID_METHOD'
    ]);
    exit();
}

// Inclure la configuration de la base de données
require_once ROOT_PATH . '/config/database.php';

// Définir le header JSON
header('Content-Type: application/json');

// Récupérer les données JSON
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Initialiser la réponse
$response = [
    'success' => false,
    'message' => '',
    'student' => null,
    'presence' => null,
    'surveillant' => null,
    'timestamp' => date('Y-m-d H:i:s')
];

try {
    // Valider les données requises
    if (empty($data['student_id'])) {
        throw new Exception('ID de l\'étudiant manquant.');
    }
    
    if (empty($data['type_presence'])) {
        throw new Exception('Type de présence non spécifié.');
    }
    
    // Vérifier les champs obligatoires pour les présences en classe
    if (in_array($data['type_presence'], ['entree_classe', 'sortie_classe', 'examen']) && empty($data['matiere_id'])) {
        throw new Exception('Une matière doit être sélectionnée pour ce type de présence.');
    }
    
    // Obtenir la connexion à la base de données
    $db = Database::getInstance()->getConnection();
    
    // Début de la transaction
    $db->beginTransaction();
    
    // 1. RECHERCHE ET VALIDATION DE L'ÉTUDIANT
    $studentQuery = "SELECT 
        e.id, 
        e.matricule, 
        e.nom, 
        e.prenom, 
        e.email, 
        e.telephone, 
        e.photo, 
        e.statut,
        e.classe_id,
        e.qr_code_data,
        e.site_id,
        c.nom as classe_nom,
        c.code as classe_code,
        c.niveau as classe_niveau,
        s.nom as site_nom
    FROM etudiants e
    LEFT JOIN classes c ON e.classe_id = c.id
    LEFT JOIN sites s ON e.site_id = s.id
    WHERE e.id = :student_id 
    AND e.site_id = :site_id";
    
    $studentStmt = $db->prepare($studentQuery);
    $studentStmt->execute([
        ':student_id' => (int)$data['student_id'],
        ':site_id' => $_SESSION['site_id']
    ]);
    
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Étudiant non trouvé ou ne faisant pas partie de votre site.');
    }
    
    // Vérifier si l'étudiant est actif
    if ($student['statut'] !== 'actif') {
        throw new Exception('L\'étudiant ' . $student['nom'] . ' ' . $student['prenom'] . ' n\'est pas actif (statut: ' . $student['statut'] . ').');
    }
    
    // 2. VÉRIFIER LES DOUBLONS (même type de présence aujourd'hui)
    $today = date('Y-m-d');
    $existingQuery = "SELECT 
        p.id, 
        p.statut, 
        p.date_heure,
        p.matiere_id,
        m.nom as matiere_nom,
        u.nom as surveillant_nom,
        u.prenom as surveillant_prenom
    FROM presences p
    LEFT JOIN matieres m ON p.matiere_id = m.id
    LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
    WHERE p.etudiant_id = :etudiant_id
    AND DATE(p.date_heure) = :today
    AND p.type_presence = :type_presence
    ORDER BY p.date_heure DESC
    LIMIT 1";
    
    $existingStmt = $db->prepare($existingQuery);
    $existingStmt->execute([
        ':etudiant_id' => $student['id'],
        ':today' => $today,
        ':type_presence' => $data['type_presence']
    ]);
    
    $existingPresence = $existingStmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. DÉTERMINER L'ACTION À EFFECTUER
    $isUpdate = false;
    $presenceId = null;
    
    if ($existingPresence) {
        // Présence existante : vérifier si on peut mettre à jour
        $lastPresenceTime = strtotime($existingPresence['date_heure']);
        $currentTime = time();
        $timeDifference = $currentTime - $lastPresenceTime;
        
        // Empêcher les mises à jour trop rapides (anti-spam)
        if ($timeDifference < 60) { // 60 secondes
            throw new Exception('Une présence ' . $data['type_presence'] . ' a déjà été enregistrée il y a moins d\'une minute.');
        }
        
        // Mettre à jour la présence existante
        $updateQuery = "UPDATE presences SET 
            date_heure = NOW(),
            statut = 'present',
            matiere_id = :matiere_id,
            surveillant_id = :surveillant_id,
            updated_at = NOW()
            WHERE id = :id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            ':matiere_id' => !empty($data['matiere_id']) ? (int)$data['matiere_id'] : null,
            ':surveillant_id' => $_SESSION['user_id'],
            ':id' => $existingPresence['id']
        ]);
        
        $isUpdate = true;
        $presenceId = $existingPresence['id'];
        
        $response['message'] = 'Présence mise à jour avec succès (entrée précédente modifiée).';
        
    } else {
        // Nouvelle présence : insertion
        $insertQuery = "INSERT INTO presences (
            etudiant_id, 
            type_presence, 
            date_heure, 
            statut, 
            matiere_id, 
            surveillant_id, 
            site_id, 
            notes,
            created_at
        ) VALUES (
            :etudiant_id, 
            :type_presence, 
            NOW(), 
            'present', 
            :matiere_id, 
            :surveillant_id, 
            :site_id, 
            :notes,
            NOW()
        )";
        
        $notes = "Saisie manuelle par surveillant";
        if (!empty($data['matiere_id'])) {
            $notes .= " - Matière ID: " . $data['matiere_id'];
        }
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            ':etudiant_id' => $student['id'],
            ':type_presence' => $data['type_presence'],
            ':matiere_id' => !empty($data['matiere_id']) ? (int)$data['matiere_id'] : null,
            ':surveillant_id' => $_SESSION['user_id'],
            ':site_id' => $_SESSION['site_id'],
            ':notes' => $notes
        ]);
        
        $presenceId = $db->lastInsertId();
        $response['message'] = 'Présence enregistrée avec succès.';
    }
    
    // 4. RÉCUPÉRER LES DÉTAILS COMPLETS DE LA PRÉSENCE
    $presenceDetailsQuery = "SELECT 
        p.id,
        p.type_presence,
        p.date_heure,
        p.statut,
        p.matiere_id,
        p.notes,
        p.created_at,
        m.nom as matiere_nom,
        m.code as matiere_code,
        u.nom as surveillant_nom,
        u.prenom as surveillant_prenom
    FROM presences p
    LEFT JOIN matieres m ON p.matiere_id = m.id
    LEFT JOIN utilisateurs u ON p.surveillant_id = u.id
    WHERE p.id = :presence_id";
    
    $presenceStmt = $db->prepare($presenceDetailsQuery);
    $presenceStmt->execute([':presence_id' => $presenceId]);
    $presence = $presenceStmt->fetch(PDO::FETCH_ASSOC);
    
    // 5. ENREGISTRER UN LOG D'AUDIT
    $auditQuery = "INSERT INTO audit_logs (
        user_id,
        action_type,
        table_name,
        record_id,
        details,
        ip_address,
        user_agent,
        created_at
    ) VALUES (
        :user_id,
        :action_type,
        :table_name,
        :record_id,
        :details,
        :ip_address,
        :user_agent,
        NOW()
    )";
    
    $auditStmt = $db->prepare($auditQuery);
    $auditStmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':action_type' => $isUpdate ? 'UPDATE' : 'CREATE',
        ':table_name' => 'presences',
        ':record_id' => $presenceId,
        ':details' => json_encode([
            'student_id' => $student['id'],
            'student_name' => $student['nom'] . ' ' . $student['prenom'],
            'type_presence' => $data['type_presence'],
            'matiere_id' => $data['matiere_id'] ?? null,
            'method' => 'manual_entry'
        ]),
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'
    ]);
    
    // 6. METTRE À JOUR LE COMPTEUR DE PRÉSENCES DE L'ÉTUDIANT (si la table existe)
    try {
        $countQuery = "UPDATE etudiants 
            SET nb_presences = COALESCE(nb_presences, 0) + 1,
                derniere_presence = NOW()
            WHERE id = :etudiant_id";
        
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute([':etudiant_id' => $student['id']]);
    } catch (Exception $e) {
        // Ignorer si les colonnes n'existent pas
        error_log("Note: Impossible de mettre à jour les compteurs de l'étudiant: " . $e->getMessage());
    }
    
    // Valider la transaction
    $db->commit();
    
    // 7. PRÉPARER LA RÉPONSE FINALE
    $response['success'] = true;
    $response['student'] = [
        'id' => $student['id'],
        'matricule' => $student['matricule'],
        'nom' => $student['nom'],
        'prenom' => $student['prenom'],
        'email' => $student['email'],
        'telephone' => $student['telephone'],
        'classe' => $student['classe_nom'],
        'classe_code' => $student['classe_code'],
        'classe_niveau' => $student['classe_niveau'],
        'site' => $student['site_nom'],
        'statut' => $student['statut'],
        'has_qr_code' => !empty($student['qr_code_data'])
    ];
    
    $response['presence'] = [
        'id' => $presence['id'],
        'type' => $presence['type_presence'],
        'type_text' => getPresenceTypeText($presence['type_presence']),
        'statut' => $presence['statut'],
        'statut_text' => getStatutText($presence['statut']),
        'date_heure' => $presence['date_heure'],
        'date_formatted' => date('d/m/Y H:i', strtotime($presence['date_heure'])),
        'matiere' => $presence['matiere_nom'],
        'matiere_code' => $presence['matiere_code'],
        'notes' => $presence['notes'],
        'is_update' => $isUpdate
    ];
    
    $response['surveillant'] = [
        'id' => $_SESSION['user_id'],
        'nom' => $_SESSION['user_name'] ?? 'Inconnu',
        'full_name' => $presence['surveillant_nom'] . ' ' . $presence['surveillant_prenom']
    ];
    
    $response['system'] = [
        'site_id' => $_SESSION['site_id'],
        'timestamp' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'version' => '1.0'
    ];
    
} catch (Exception $e) {
    // Annuler la transaction en cas d'erreur
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    $response['error_code'] = 'PROCESS_ERROR';
    $response['error_details'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Journaliser l'erreur (pour le débogage)
    error_log("Erreur process_manual_entry.php: " . $e->getMessage() . " dans " . $e->getFile() . " ligne " . $e->getLine());
}

// Fonctions utilitaires pour formater le texte
function getPresenceTypeText($type) {
    $types = [
        'entree_ecole' => 'Entrée à l\'École',
        'sortie_ecole' => 'Sortie de l\'École',
        'entree_classe' => 'Entrée en Classe',
        'sortie_classe' => 'Sortie de Classe',
        'examen' => 'Examen',
        'reunion' => 'Réunion',
        'atelier' => 'Atelier',
        'sport' => 'Activité Sportive'
    ];
    return $types[$type] ?? $type;
}

function getStatutText($statut) {
    $statuts = [
        'present' => 'Présent',
        'absent' => 'Absent',
        'retard' => 'En retard',
        'justifie' => 'Absence justifiée',
        'maladie' => 'Maladie',
        'exception' => 'Exception'
    ];
    return $statuts[$statut] ?? $statut;
}

// Retourner la réponse JSON
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit();

// Version simplifiée pour le développement - à décommenter si besoin
/*
// SIMULATION DE RÉPONSE POUR TESTS
$response = [
    'success' => true,
    'message' => 'Présence enregistrée avec succès (mode simulation)',
    'student' => [
        'id' => $data['student_id'],
        'matricule' => 'ISGI-2024-' . str_pad($data['student_id'], 4, '0', STR_PAD_LEFT),
        'nom' => 'DUPONT',
        'prenom' => 'Jean',
        'classe' => 'Licence 1 Informatique',
        'statut' => 'actif'
    ],
    'presence' => [
        'id' => rand(1000, 9999),
        'type' => $data['type_presence'],
        'type_text' => getPresenceTypeText($data['type_presence']),
        'statut' => 'present',
        'statut_text' => 'Présent',
        'date_heure' => date('Y-m-d H:i:s'),
        'date_formatted' => date('d/m/Y H:i'),
        'matiere' => isset($data['matiere_id']) ? 'Algorithmique' : null
    ],
    'surveillant' => [
        'id' => $_SESSION['user_id'],
        'nom' => $_SESSION['user_name'] ?? 'Surveillant Test',
        'full_name' => $_SESSION['user_name'] ?? 'Surveillant Test'
    ],
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response, JSON_PRETTY_PRINT);
*/