<?php
session_start();
require_once 'config/database.php';

// Vérifier si la fonction sanitize n'existe pas déjà
if (!function_exists('sanitize')) {
    function sanitize($input) {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

// Fonction pour générer un numéro de demande
function genererNumeroDemande($db) {
    $annee = date('Y');
    $max_tentatives = 3;
    
    for ($tentative = 0; $tentative < $max_tentatives; $tentative++) {
        try {
            // Chercher le plus grand numéro existant pour cette année
            $stmt = $db->prepare("
                SELECT SUBSTRING_INDEX(numero_demande, '-', -1) as dernier_num
                FROM demande_inscriptions 
                WHERE numero_demande LIKE CONCAT('ISGI-', :annee, '-%')
                ORDER BY CAST(SUBSTRING_INDEX(numero_demande, '-', -1) AS UNSIGNED) DESC
                LIMIT 1
            ");
            
            $stmt->execute([':annee' => $annee]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            if ($result && isset($result['dernier_num']) && is_numeric($result['dernier_num'])) {
                $sequence_num = intval($result['dernier_num']) + 1;
            } else {
                $sequence_num = 1;
            }
            
            // Générer le numéro
            $numero_demande = 'ISGI-' . $annee . '-' . str_pad($sequence_num, 5, '0', STR_PAD_LEFT);
            
            // Vérifier qu'il n'existe pas déjà (concurrence)
            $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM demande_inscriptions WHERE numero_demande = ?");
            $checkStmt->execute([$numero_demande]);
            $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
            $checkStmt->closeCursor();
            
            if ($checkResult['count'] == 0) {
                return $numero_demande;
            }
            
            // Si le numéro existe déjà, essayer avec un incrément
            $sequence_num++;
            
        } catch (Exception $e) {
            error_log("Erreur génération numéro demande (tentative $tentative): " . $e->getMessage());
            
            // En cas d'erreur SQL, essayer une méthode alternative
            if ($tentative == $max_tentatives - 1) {
                // Dernière tentative : utiliser timestamp
                return 'ISGI-' . $annee . '-' . substr(uniqid('', true), -8);
            }
        }
    }
    
    // Fallback en cas d'échec
    return 'ISGI-' . $annee . '-' . date('His') . rand(100, 999);
}

// Fonction d'upload de fichier
function uploadFile($file, $type) {
    $uploadDir = 'uploads/demandes/';
    
    // Créer le dossier s'il n'existe pas
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Vérifier le type de fichier
    $allowedImageTypes = ['image/jpeg', 'image/jpg', 'image/png'];
    $allowedDocTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    
    $fileType = mime_content_type($file['tmp_name']);
    $fileSize = $file['size'];
    
    if ($type === 'photo') {
        if (!in_array($fileType, $allowedImageTypes)) {
            return ['error' => 'Le format de la photo n\'est pas accepté. Formats acceptés: JPG, PNG'];
        }
    } else {
        if (!in_array($fileType, $allowedDocTypes)) {
            return ['error' => 'Le format du document n\'est pas accepté. Formats acceptés: PDF, JPG, PNG'];
        }
    }
    
    // Vérifier la taille (max 2MB)
    if ($fileSize > 2 * 1024 * 1024) {
        return ['error' => 'Le fichier est trop volumineux (max 2MB)'];
    }
    
    // Générer un nom unique
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = $type . '_' . uniqid() . '_' . date('YmdHis') . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    // Déplacer le fichier
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        return ['success' => true, 'path' => $filePath, 'name' => $fileName];
    }
    
    return ['error' => 'Erreur lors du téléchargement du fichier'];
}

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inscription.php');
    exit();
}

// Debug: Afficher les données reçues (à enlever en production)
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

// Initialiser la connexion à la base
$database = Database::getInstance();
$db = $database->getConnection();

// Tableau pour stocker les erreurs
$errors = [];

// Récupérer et nettoyer les données
$data = [
    'cycle_formation' => sanitize($_POST['cycle_formation'] ?? ''),
    'type_rentree' => sanitize($_POST['type_rentree'] ?? ''),
    'domaine' => sanitize($_POST['domaine'] ?? ''),
    'niveau' => sanitize($_POST['niveau'] ?? ''),
    'filiere' => sanitize($_POST['filiere'] ?? ''),
    'type_formation' => sanitize($_POST['type_formation'] ?? ''),
    'ecole' => sanitize($_POST['ecole'] ?? ''),
    'site_formation' => sanitize($_POST['site_formation'] ?? ''),
    'numero_cni' => sanitize($_POST['numero_cni'] ?? ''),
    'sexe' => sanitize($_POST['sexe'] ?? ''),
    'nom' => sanitize($_POST['nom'] ?? ''),
    'prenom' => sanitize($_POST['prenom'] ?? ''),
    'date_naissance' => sanitize($_POST['date_naissance'] ?? ''),
    'lieu_naissance' => sanitize($_POST['lieu_naissance'] ?? ''),
    'nationalite' => sanitize($_POST['nationalite'] ?? 'Congolaise'),
    'adresse' => sanitize($_POST['adresse'] ?? ''),
    'pays' => sanitize($_POST['pays'] ?? ''),
    'ville' => sanitize($_POST['ville'] ?? ''),
    'indicatif' => sanitize($_POST['indicatif'] ?? '+242'),
    'telephone' => sanitize($_POST['telephone'] ?? ''),
    'email' => sanitize($_POST['email'] ?? ''),
    'profession' => sanitize($_POST['profession'] ?? ''),
    'situation_matrimoniale' => sanitize($_POST['situation_matrimoniale'] ?? ''),
    'nom_pere' => sanitize($_POST['nom_pere'] ?? ''),
    'profession_pere' => sanitize($_POST['profession_pere'] ?? ''),
    'nom_mere' => sanitize($_POST['nom_mere'] ?? ''),
    'profession_mere' => sanitize($_POST['profession_mere'] ?? ''),
    'indicatif_parent' => sanitize($_POST['indicatif_parent'] ?? '+242'),
    'telephone_parent' => sanitize($_POST['telephone_parent'] ?? ''),
    'nom_tuteur' => sanitize($_POST['nom_tuteur'] ?? ''),
    'profession_tuteur' => sanitize($_POST['profession_tuteur'] ?? ''),
    'indicatif_tuteur' => sanitize($_POST['indicatif_tuteur'] ?? '+242'),
    'telephone_tuteur' => sanitize($_POST['telephone_tuteur'] ?? ''),
    'lieu_service_tuteur' => sanitize($_POST['lieu_service_tuteur'] ?? ''),
    'mode_paiement' => sanitize($_POST['mode_paiement'] ?? ''),
    'periodicite_paiement' => sanitize($_POST['periodicite_paiement'] ?? ''),
    'comment_connaissance' => sanitize($_POST['comment_connaissance'] ?? ''),
    'commentaires' => sanitize($_POST['commentaires'] ?? '')
];

// Debug: Vérifier les données nettoyées
error_log("Cleaned data: " . print_r($data, true));

// Validation des données obligatoires
$required_fields = [
    'cycle_formation', 'type_rentree', 'domaine', 'niveau', 'filiere', 'type_formation',
    'site_formation', 'numero_cni', 'sexe', 'nom', 'prenom', 'date_naissance',
    'lieu_naissance', 'nationalite', 'adresse', 'pays', 'ville', 'telephone', 'email',
    'profession', 'situation_matrimoniale', 'nom_pere', 'nom_mere', 'telephone_parent',
    'mode_paiement', 'periodicite_paiement', 'comment_connaissance'
];

foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        $errors[] = "Le champ " . str_replace('_', ' ', $field) . " est obligatoire.";
    }
}

// Validation spécifique
if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = "L'adresse email n'est pas valide.";
}

// Validation date de naissance (minimum 16 ans)
if (!empty($data['date_naissance'])) {
    $birthDate = new DateTime($data['date_naissance']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    
    if ($age < 16) {
        $errors[] = "Vous devez avoir au moins 16 ans pour vous inscrire.";
    }
}

// Vérification des doublons d'email dans les demandes en attente
if (!empty($data['email'])) {
    try {
        $stmt = $db->prepare("SELECT id FROM demande_inscriptions WHERE email = ? AND statut IN ('en_attente', 'en_traitement')");
        $stmt->execute([$data['email']]);
        
        if ($stmt->rowCount() > 0) {
            $errors[] = "Une demande est déjà en cours pour cet email.";
        }
        $stmt->closeCursor();
    } catch (Exception $e) {
        // Ignorer si la table n'existe pas encore
    }
}

// Vérification des doublons de CNI
if (!empty($data['numero_cni'])) {
    try {
        $stmt = $db->prepare("SELECT id FROM demande_inscriptions WHERE numero_cni = ? AND statut IN ('en_attente', 'en_traitement')");
        $stmt->execute([$data['numero_cni']]);
        
        if ($stmt->rowCount() > 0) {
            $errors[] = "Une demande est déjà en cours pour ce numéro CNI/NUI.";
        }
        $stmt->closeCursor();
    } catch (Exception $e) {
        // Ignorer si la table n'existe pas encore
    }
}

// Gestion des uploads de fichiers
$uploadedFiles = [];
$documentFields = [
    'photo_identite' => 'photo',
    'acte_naissance' => 'acte',
    'releve_notes' => 'releve',
    'attestation_legalisee' => 'attestation'
];

foreach ($documentFields as $field => $type) {
    if (isset($_FILES[$field]) && $_FILES[$field]['error'] == UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES[$field], $type);
        if (isset($uploadResult['error'])) {
            $errors[] = $uploadResult['error'] . " (" . str_replace('_', ' ', $field) . ")";
        } else {
            $uploadedFiles[$field] = $uploadResult['path'];
        }
    } else {
        // Vérifier le type d'erreur
        if (isset($_FILES[$field])) {
            switch ($_FILES[$field]['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = "Le fichier " . str_replace('_', ' ', $field) . " est trop volumineux.";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = "Le téléchargement du fichier " . str_replace('_', ' ', $field) . " a été interrompu.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errors[] = "Le document " . str_replace('_', ' ', $field) . " est requis.";
                    break;
                default:
                    $errors[] = "Erreur lors du téléchargement du fichier " . str_replace('_', ' ', $field) . ".";
            }
        } else {
            $errors[] = "Le document " . str_replace('_', ' ', $field) . " est requis.";
        }
    }
}

// Gestion des autres documents (optionnels)
$autresDocuments = [];
if (isset($_FILES['autres_documents']) && is_array($_FILES['autres_documents']['name'])) {
    for ($i = 0; $i < count($_FILES['autres_documents']['name']); $i++) {
        if ($_FILES['autres_documents']['error'][$i] == UPLOAD_ERR_OK) {
            $file = [
                'name' => $_FILES['autres_documents']['name'][$i],
                'type' => $_FILES['autres_documents']['type'][$i],
                'tmp_name' => $_FILES['autres_documents']['tmp_name'][$i],
                'error' => $_FILES['autres_documents']['error'][$i],
                'size' => $_FILES['autres_documents']['size'][$i]
            ];
            
            $uploadResult = uploadFile($file, 'autre_' . $i);
            if (!isset($uploadResult['error'])) {
                $autresDocuments[] = $uploadResult['path'];
            }
        }
    }
}

// Debug: Afficher les erreurs
error_log("Errors: " . print_r($errors, true));

// Si pas d'erreurs, procéder à l'insertion dans demande_inscriptions
if (empty($errors)) {
    try {
        // Préparer les données pour l'insertion
        $telephone_complet = $data['indicatif'] . $data['telephone'];
        $telephone_parent_complet = $data['indicatif_parent'] . $data['telephone_parent'];
        $telephone_tuteur_complet = !empty($data['telephone_tuteur']) ? $data['indicatif_tuteur'] . $data['telephone_tuteur'] : '';
        
        // Générer le numéro de demande
        $numero_demande = genererNumeroDemande($db);
        
        // Convertir les chemins de fichiers en JSON
        $documents_json = json_encode($uploadedFiles);
        $autres_documents_json = !empty($autresDocuments) ? json_encode($autresDocuments) : null;
        
        // Insérer dans demande_inscriptions
        $sql = "INSERT INTO demande_inscriptions (
            numero_demande, nom, prenom, date_naissance, lieu_naissance, sexe,
            numero_cni, nationalite, adresse, pays, ville, telephone, email,
            profession, situation_matrimoniale, cycle_formation, type_rentree,
            domaine, niveau, filiere, type_formation, ecole, site_formation,
            nom_pere, profession_pere, nom_mere, profession_mere, telephone_parent,
            nom_tuteur, profession_tuteur, telephone_tuteur, lieu_service_tuteur,
            mode_paiement, periodicite_paiement, comment_connaissance, commentaires,
            photo_identite, acte_naissance, releve_notes, attestation_legalisee,
            autres_documents, statut, date_demande
        ) VALUES (
            :numero_demande, :nom, :prenom, :date_naissance, :lieu_naissance, :sexe,
            :numero_cni, :nationalite, :adresse, :pays, :ville, :telephone, :email,
            :profession, :situation_matrimoniale, :cycle_formation, :type_rentree,
            :domaine, :niveau, :filiere, :type_formation, :ecole, :site_formation,
            :nom_pere, :profession_pere, :nom_mere, :profession_mere, :telephone_parent,
            :nom_tuteur, :profession_tuteur, :telephone_tuteur, :lieu_service_tuteur,
            :mode_paiement, :periodicite_paiement, :comment_connaissance, :commentaires,
            :photo_identite, :acte_naissance, :releve_notes, :attestation_legalisee,
            :autres_documents, 'en_attente', NOW()
        )";
        
        error_log("SQL: " . $sql);
        
        $stmt = $db->prepare($sql);
        
        $params = [
            ':numero_demande' => $numero_demande,
            ':nom' => $data['nom'],
            ':prenom' => $data['prenom'],
            ':date_naissance' => $data['date_naissance'],
            ':lieu_naissance' => $data['lieu_naissance'],
            ':sexe' => $data['sexe'],
            ':numero_cni' => $data['numero_cni'],
            ':nationalite' => $data['nationalite'],
            ':adresse' => $data['adresse'],
            ':pays' => $data['pays'],
            ':ville' => $data['ville'],
            ':telephone' => $telephone_complet,
            ':email' => $data['email'],
            ':profession' => $data['profession'],
            ':situation_matrimoniale' => $data['situation_matrimoniale'],
            ':cycle_formation' => $data['cycle_formation'],
            ':type_rentree' => $data['type_rentree'],
            ':domaine' => $data['domaine'],
            ':niveau' => $data['niveau'],
            ':filiere' => $data['filiere'],
            ':type_formation' => $data['type_formation'],
            ':ecole' => $data['ecole'],
            ':site_formation' => $data['site_formation'],
            ':nom_pere' => $data['nom_pere'],
            ':profession_pere' => $data['profession_pere'],
            ':nom_mere' => $data['nom_mere'],
            ':profession_mere' => $data['profession_mere'],
            ':telephone_parent' => $telephone_parent_complet,
            ':nom_tuteur' => $data['nom_tuteur'],
            ':profession_tuteur' => $data['profession_tuteur'],
            ':telephone_tuteur' => $telephone_tuteur_complet,
            ':lieu_service_tuteur' => $data['lieu_service_tuteur'],
            ':mode_paiement' => $data['mode_paiement'],
            ':periodicite_paiement' => $data['periodicite_paiement'],
            ':comment_connaissance' => $data['comment_connaissance'],
            ':commentaires' => $data['commentaires'],
            ':photo_identite' => $uploadedFiles['photo_identite'] ?? null,
            ':acte_naissance' => $uploadedFiles['acte_naissance'] ?? null,
            ':releve_notes' => $uploadedFiles['releve_notes'] ?? null,
            ':attestation_legalisee' => $uploadedFiles['attestation_legalisee'] ?? null,
            ':autres_documents' => $autres_documents_json
        ];
        
        error_log("Params: " . print_r($params, true));
        
        $result = $stmt->execute($params);
        
        if ($result) {
            $demande_id = $db->lastInsertId();
            error_log("Insertion réussie, ID: " . $demande_id);
            
            // Enregistrer le paiement (optionnel, pour suivi)
            $frais_inscription = 25000;
            $reference = 'DEM-' . date('Ymd-His') . '-' . rand(1000, 9999);
            
            try {
                $sql_paiement = "INSERT INTO paiements (
                    demande_inscription_id, numero_demande, type_paiement, montant_total, 
                    montant_paye, mode_paiement, reference_paiement, statut
                ) VALUES (
                    :demande_id, :numero_demande, 'Inscription', :montant_total, 0,
                    :mode_paiement, :reference, 'en_attente'
                )";
                
                $stmt_paiement = $db->prepare($sql_paiement);
                $stmt_paiement->execute([
                    ':demande_id' => $demande_id,
                    ':numero_demande' => $numero_demande,
                    ':montant_total' => $frais_inscription,
                    ':mode_paiement' => $data['mode_paiement'],
                    ':reference' => $reference
                ]);
                $stmt_paiement->closeCursor();
            } catch (Exception $e) {
                // Ignorer l'erreur de paiement
                error_log("Erreur paiement: " . $e->getMessage());
            }
            
            // Stocker en session pour la confirmation
            $_SESSION['inscription_success'] = true;
            $_SESSION['numero_demande'] = $numero_demande;
            $_SESSION['nom_complet'] = $data['nom'] . ' ' . $data['prenom'];
            $_SESSION['email'] = $data['email'];
            
            // Rediriger vers la page de confirmation
            header('Location: confirmation.php');
            exit();
        } else {
            $errors[] = "Erreur lors de l'exécution de la requête SQL.";
        }
        
        $stmt->closeCursor();
        
    } catch (PDOException $e) {
        $errors[] = "Erreur lors de l'enregistrement de la demande: " . $e->getMessage();
        error_log("Erreur PDO demande_inscriptions: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    } catch (Exception $e) {
        $errors[] = "Erreur générale: " . $e->getMessage();
        error_log("Erreur générale: " . $e->getMessage());
    }
}

// Si erreurs
if (!empty($errors)) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_data'] = $data;
    header('Location: confirmation.php');
    exit();
}