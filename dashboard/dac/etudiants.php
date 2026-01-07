<?php
// dashboard/dac/etudiants.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 5) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Définir les fonctions de formatage
function formatDateFr($date, $format = 'd/m/Y') {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '';
    }
    
    try {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }
        return date($format, $timestamp);
    } catch (Exception $e) {
        return '';
    }
}

function formatMoney($amount) {
    if ($amount === null || $amount === '' || $amount == 0) {
        return '0 FCFA';
    }
    
    $amount = floatval($amount);
    if (!is_numeric($amount)) {
        return '0 FCFA';
    }
    
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function getStatusBadge($statut) {
    switch (strtolower($statut)) {
        case 'actif':
        case 'valide':
        case 'present':
        case 'admis':
            return '<span class="badge bg-success">Actif</span>';
        case 'inactif':
        case 'en_attente':
        case 'en_cours':
            return '<span class="badge bg-warning">En attente</span>';
        case 'annule':
        case 'rejete':
        case 'absent':
            return '<span class="badge bg-danger">Annulé</span>';
        case 'termine':
        case 'validee':
            return '<span class="badge bg-info">Terminé</span>';
        case 'diplome':
            return '<span class="badge bg-primary">Diplômé</span>';
        case 'abandonne':
            return '<span class="badge bg-danger">Abandonné</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
    }
}

function escapeHtml($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

@include_once ROOT_PATH . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    $site_id = $_SESSION['site_id'] ?? null;
    
    $pageTitle = "Gestion des Étudiants";
    
    // Variables pour la pagination et la recherche
    $action = $_GET['action'] ?? 'list';
    $page = max(1, intval($_GET['page'] ?? 1));
    $search = $_GET['search'] ?? '';
    $classe_id = $_GET['classe_id'] ?? null;
    $statut = $_GET['statut'] ?? '';
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    // Variables pour les messages
    $message = $_GET['message'] ?? null;
    $message_type = $_GET['message_type'] ?? 'success';
    $error = $_GET['error'] ?? null;
    
    // Actions CRUD
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['create_etudiant'])) {
            // Récupérer les données du formulaire
            $data = [
                'site_id' => $site_id,
                'nom' => trim($_POST['nom']),
                'prenom' => trim($_POST['prenom']),
                'numero_cni' => trim($_POST['numero_cni']),
                'date_naissance' => $_POST['date_naissance'],
                'lieu_naissance' => trim($_POST['lieu_naissance']),
                'sexe' => $_POST['sexe'],
                'nationalite' => $_POST['nationalite'] ?? 'Congolaise',
                'adresse' => trim($_POST['adresse']),
                'ville' => trim($_POST['ville']),
                'pays' => $_POST['pays'] ?? 'Congo',
                'profession' => trim($_POST['profession'] ?? ''),
                'situation_matrimoniale' => $_POST['situation_matrimoniale'] ?? '',
                'statut' => 'actif',
                'date_inscription' => date('Y-m-d')
            ];
            
            // Gérer la photo
            if (isset($_FILES['photo_identite']) && $_FILES['photo_identite']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = ROOT_PATH . '/uploads/etudiants/photos/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = time() . '_' . basename($_FILES['photo_identite']['name']);
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['photo_identite']['tmp_name'], $file_path)) {
                    $data['photo_identite'] = 'uploads/etudiants/photos/' . $file_name;
                }
            }
            
            // Générer un matricule
            $matricule = 'ISGI-' . date('Y') . '-' . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
            $data['matricule'] = $matricule;
            
            // Insérer dans la base de données
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $query = "INSERT INTO etudiants ($columns) VALUES ($placeholders)";
            $stmt = $db->prepare($query);
            $stmt->execute($data);
            
            $new_id = $db->lastInsertId();
            
            // Créer un compte utilisateur si email fourni
            if (!empty($_POST['email'])) {
                $email = trim($_POST['email']);
                
                // Vérifier si l'email existe déjà
                $check_query = "SELECT id FROM utilisateurs WHERE email = ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([$email]);
                
                if (!$check_stmt->fetch()) {
                    // Créer le mot de passe
                    $mot_de_passe = password_hash('isgi123', PASSWORD_DEFAULT);
                    
                    $user_query = "INSERT INTO utilisateurs (role_id, site_id, email, mot_de_passe, nom, prenom, telephone, statut) 
                                   VALUES (8, ?, ?, ?, ?, ?, ?, 'actif')";
                    $user_stmt = $db->prepare($user_query);
                    $user_stmt->execute([
                        $site_id,
                        $email,
                        $mot_de_passe,
                        $data['nom'],
                        $data['prenom'],
                        $_POST['telephone'] ?? ''
                    ]);
                    
                    $user_id = $db->lastInsertId();
                    
                    // Lier l'étudiant au compte utilisateur
                    $link_query = "UPDATE etudiants SET utilisateur_id = ? WHERE id = ?";
                    $link_stmt = $db->prepare($link_query);
                    $link_stmt->execute([$user_id, $new_id]);
                }
            }
            
            // Assigner à une classe si spécifiée
            if (!empty($_POST['classe_id'])) {
                $classe_query = "UPDATE etudiants SET classe_id = ? WHERE id = ?";
                $classe_stmt = $db->prepare($classe_query);
                $classe_stmt->execute([$_POST['classe_id'], $new_id]);
            }
            
            header('Location: etudiants.php?action=view&id=' . $new_id . '&message=Étudiant créé avec succès&message_type=success');
            exit();
            
        } elseif (isset($_POST['update_etudiant'])) {
            $etudiant_id = $_POST['etudiant_id'];
            
            // Récupérer les données du formulaire
            $data = [
                'nom' => trim($_POST['nom']),
                'prenom' => trim($_POST['prenom']),
                'numero_cni' => trim($_POST['numero_cni']),
                'date_naissance' => $_POST['date_naissance'],
                'lieu_naissance' => trim($_POST['lieu_naissance']),
                'sexe' => $_POST['sexe'],
                'nationalite' => $_POST['nationalite'] ?? 'Congolaise',
                'adresse' => trim($_POST['adresse']),
                'ville' => trim($_POST['ville']),
                'pays' => $_POST['pays'] ?? 'Congo',
                'profession' => trim($_POST['profession'] ?? ''),
                'situation_matrimoniale' => $_POST['situation_matrimoniale'] ?? '',
                'nom_pere' => trim($_POST['nom_pere'] ?? ''),
                'profession_pere' => trim($_POST['profession_pere'] ?? ''),
                'nom_mere' => trim($_POST['nom_mere'] ?? ''),
                'profession_mere' => trim($_POST['profession_mere'] ?? ''),
                'telephone_parent' => trim($_POST['telephone_parent'] ?? ''),
                'nom_tuteur' => trim($_POST['nom_tuteur'] ?? ''),
                'profession_tuteur' => trim($_POST['profession_tuteur'] ?? ''),
                'telephone_tuteur' => trim($_POST['telephone_tuteur'] ?? ''),
                'lieu_service_tuteur' => trim($_POST['lieu_service_tuteur'] ?? ''),
                'statut' => $_POST['statut'] ?? 'actif',
                'classe_id' => !empty($_POST['classe_id']) ? $_POST['classe_id'] : null
            ];
            
            // Gérer la photo
            if (isset($_FILES['photo_identite']) && $_FILES['photo_identite']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = ROOT_PATH . '/uploads/etudiants/photos/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = time() . '_' . basename($_FILES['photo_identite']['name']);
                $file_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['photo_identite']['tmp_name'], $file_path)) {
                    $data['photo_identite'] = 'uploads/etudiants/photos/' . $file_name;
                    
                    // Supprimer l'ancienne photo si elle existe
                    $old_photo_query = "SELECT photo_identite FROM etudiants WHERE id = ?";
                    $old_photo_stmt = $db->prepare($old_photo_query);
                    $old_photo_stmt->execute([$etudiant_id]);
                    $old_photo = $old_photo_stmt->fetchColumn();
                    
                    if ($old_photo && file_exists(ROOT_PATH . '/' . $old_photo)) {
                        unlink(ROOT_PATH . '/' . $old_photo);
                    }
                }
            }
            
            // Gérer les documents
            $document_fields = ['acte_naissance', 'releve_notes', 'attestation_legalisee'];
            foreach ($document_fields as $field) {
                if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = ROOT_PATH . '/uploads/etudiants/documents/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_name = $field . '_' . time() . '_' . basename($_FILES[$field]['name']);
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES[$field]['tmp_name'], $file_path)) {
                        $data[$field] = 'uploads/etudiants/documents/' . $file_name;
                        
                        // Supprimer l'ancien document s'il existe
                        $old_doc_query = "SELECT $field FROM etudiants WHERE id = ?";
                        $old_doc_stmt = $db->prepare($old_doc_query);
                        $old_doc_stmt->execute([$etudiant_id]);
                        $old_doc = $old_doc_stmt->fetchColumn();
                        
                        if ($old_doc && file_exists(ROOT_PATH . '/' . $old_doc)) {
                            unlink(ROOT_PATH . '/' . $old_doc);
                        }
                    }
                }
            }
            
            // Mettre à jour dans la base de données
            $set_parts = [];
            foreach ($data as $key => $value) {
                if ($value === null) {
                    $set_parts[] = "$key = NULL";
                } else {
                    $set_parts[] = "$key = :$key";
                }
            }
            
            $query = "UPDATE etudiants SET " . implode(', ', $set_parts) . " WHERE id = :id AND site_id = :site_id";
            
            // Ajouter les paramètres
            $data['id'] = $etudiant_id;
            $data['site_id'] = $site_id;
            
            $stmt = $db->prepare($query);
            
            // Bind les paramètres
            foreach ($data as $key => $value) {
                if ($value === null) {
                    $stmt->bindValue(":$key", null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue(":$key", $value);
                }
            }
            
            $stmt->execute();
            
            // Mettre à jour l'email de l'utilisateur si fourni
            if (!empty($_POST['email'])) {
                $email = trim($_POST['email']);
                
                // Vérifier si l'étudiant a un compte utilisateur
                $user_check = "SELECT utilisateur_id FROM etudiants WHERE id = ?";
                $user_check_stmt = $db->prepare($user_check);
                $user_check_stmt->execute([$etudiant_id]);
                $user_id = $user_check_stmt->fetchColumn();
                
                if ($user_id) {
                    // Mettre à jour l'email
                    $update_email = "UPDATE utilisateurs SET email = ? WHERE id = ?";
                    $update_email_stmt = $db->prepare($update_email);
                    $update_email_stmt->execute([$email, $user_id]);
                } else if (!empty($email)) {
                    // Créer un nouveau compte
                    $mot_de_passe = password_hash('isgi123', PASSWORD_DEFAULT);
                    
                    $new_user = "INSERT INTO utilisateurs (role_id, site_id, email, mot_de_passe, nom, prenom, telephone, statut) 
                                 VALUES (8, ?, ?, ?, ?, ?, ?, 'actif')";
                    $new_user_stmt = $db->prepare($new_user);
                    $new_user_stmt->execute([
                        $site_id,
                        $email,
                        $mot_de_passe,
                        $data['nom'],
                        $data['prenom'],
                        $_POST['telephone'] ?? ''
                    ]);
                    
                    $new_user_id = $db->lastInsertId();
                    
                    // Lier l'étudiant au compte
                    $link_user = "UPDATE etudiants SET utilisateur_id = ? WHERE id = ?";
                    $link_user_stmt = $db->prepare($link_user);
                    $link_user_stmt->execute([$new_user_id, $etudiant_id]);
                }
            }
            
            header('Location: etudiants.php?action=view&id=' . $etudiant_id . '&message=Étudiant mis à jour avec succès&message_type=success');
            exit();
            
        } elseif (isset($_POST['delete_etudiant'])) {
            $etudiant_id = $_POST['etudiant_id'];
            
            // Désactiver l'étudiant plutôt que de le supprimer
            $query = "UPDATE etudiants SET statut = 'inactif' WHERE id = ? AND site_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$etudiant_id, $site_id]);
            
            // Désactiver également le compte utilisateur
            $user_query = "UPDATE utilisateurs u 
                          JOIN etudiants e ON u.id = e.utilisateur_id 
                          SET u.statut = 'inactif' 
                          WHERE e.id = ? AND e.site_id = ?";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->execute([$etudiant_id, $site_id]);
            
            header('Location: etudiants.php?message=Étudiant désactivé avec succès&message_type=success');
            exit();
        }
    }
    
    // Récupérer les données selon l'action
    switch ($action) {
        case 'view':
            $etudiant_id = $_GET['id'] ?? null;
            if ($etudiant_id) {
                $query = "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, 
                                 f.nom as filiere_nom, n.libelle as niveau_libelle,
                                 u.email, u.telephone as user_phone
                          FROM etudiants e
                          LEFT JOIN sites s ON e.site_id = s.id
                          LEFT JOIN classes c ON e.classe_id = c.id
                          LEFT JOIN inscriptions i ON e.id = i.etudiant_id
                          LEFT JOIN filieres f ON i.filiere_id = f.id
                          LEFT JOIN niveaux n ON i.niveau = n.code
                          LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                          WHERE e.id = ? AND e.site_id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$etudiant_id, $site_id]);
                $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$etudiant) {
                    header('Location: etudiants.php?error=Étudiant non trouvé');
                    exit();
                }
                
                // Récupérer les bulletins de l'étudiant
                $bulletin_query = "SELECT b.*, aa.libelle as annee_libelle, se.numero as semestre_num
                                  FROM bulletins b
                                  JOIN annees_academiques aa ON b.annee_academique_id = aa.id
                                  JOIN semestres se ON b.semestre_id = se.id
                                  WHERE b.etudiant_id = ?
                                  ORDER BY b.annee_academique_id DESC, b.semestre_id DESC";
                $bulletin_stmt = $db->prepare($bulletin_query);
                $bulletin_stmt->execute([$etudiant_id]);
                $bulletins = $bulletin_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Récupérer les présences récentes
                $presence_query = "SELECT p.*, m.nom as matiere_nom
                                  FROM presences p
                                  LEFT JOIN matieres m ON p.matiere_id = m.id
                                  WHERE p.etudiant_id = ?
                                  ORDER BY p.date_heure DESC
                                  LIMIT 10";
                $presence_stmt = $db->prepare($presence_query);
                $presence_stmt->execute([$etudiant_id]);
                $presences = $presence_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Récupérer les paiements
                $paiement_query = "SELECT p.*, tf.nom as type_frais
                                  FROM paiements p
                                  JOIN types_frais tf ON p.type_frais_id = tf.id
                                  WHERE p.etudiant_id = ?
                                  ORDER BY p.date_paiement DESC
                                  LIMIT 10";
                $paiement_stmt = $db->prepare($paiement_query);
                $paiement_stmt->execute([$etudiant_id]);
                $paiements = $paiement_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Récupérer les notes
                $note_query = "SELECT n.*, m.nom as matiere_nom, te.nom as type_examen
                              FROM notes n
                              JOIN matieres m ON n.matiere_id = m.id
                              JOIN types_examens te ON n.type_examen_id = te.id
                              WHERE n.etudiant_id = ?
                              ORDER BY n.date_evaluation DESC
                              LIMIT 10";
                $note_stmt = $db->prepare($note_query);
                $note_stmt->execute([$etudiant_id]);
                $notes = $note_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            break;
            
        case 'edit':
            $etudiant_id = $_GET['id'] ?? null;
            if ($etudiant_id) {
                $query = "SELECT e.*, u.email, u.telephone as user_phone
                          FROM etudiants e
                          LEFT JOIN utilisateurs u ON e.utilisateur_id = u.id
                          WHERE e.id = ? AND e.site_id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$etudiant_id, $site_id]);
                $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$etudiant) {
                    header('Location: etudiants.php?error=Étudiant non trouvé');
                    exit();
                }
            }
            break;
            
        case 'create':
            // Préparer les données pour un nouvel étudiant
            break;
            
        default: // 'list'
            // Construire la requête avec filtres
            $query = "SELECT e.*, s.nom as site_nom, c.nom as classe_nom, 
                             f.nom as filiere_nom, n.libelle as niveau_libelle
                      FROM etudiants e
                      LEFT JOIN sites s ON e.site_id = s.id
                      LEFT JOIN classes c ON e.classe_id = c.id
                      LEFT JOIN inscriptions i ON e.id = i.etudiant_id
                      LEFT JOIN filieres f ON i.filiere_id = f.id
                      LEFT JOIN niveaux n ON i.niveau = n.code
                      WHERE e.site_id = :site_id";
            
            $params = ['site_id' => $site_id];
            
            // Appliquer les filtres
            if (!empty($search)) {
                $query .= " AND (e.matricule LIKE :search OR e.nom LIKE :search OR e.prenom LIKE :search OR e.numero_cni LIKE :search)";
                $params['search'] = "%$search%";
            }
            
            if (!empty($classe_id)) {
                $query .= " AND e.classe_id = :classe_id";
                $params['classe_id'] = $classe_id;
            }
            
            if (!empty($statut)) {
                $query .= " AND e.statut = :statut";
                $params['statut'] = $statut;
            }
            
            // Compter le nombre total
            $count_query = str_replace("SELECT e.*, s.nom as site_nom", "SELECT COUNT(*) as total", $query);
            $count_query = preg_replace('/ORDER BY.*$/', '', $count_query);
            $count_query = preg_replace('/LIMIT.*$/', '', $count_query);
            
            $count_stmt = $db->prepare($count_query);
            foreach ($params as $key => $value) {
                $param_type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $count_stmt->bindValue(':' . $key, $value, $param_type);
            }
            $count_stmt->execute();
            $total_count = $count_stmt->fetchColumn();
            $total_pages = ceil($total_count / $limit);
            
            // Ajouter le tri et la pagination
            $query .= " ORDER BY e.date_inscription DESC LIMIT :limit OFFSET :offset";
            $params['limit'] = $limit;
            $params['offset'] = $offset;
            
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                $param_type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(':' . $key, $value, $param_type);
            }
            $stmt->execute();
            $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    // Récupérer les classes pour les filtres
    $classe_query = "SELECT c.*, f.nom as filiere_nom, n.libelle as niveau_libelle 
                     FROM classes c
                     JOIN filieres f ON c.filiere_id = f.id
                     JOIN niveaux n ON c.niveau_id = n.id
                     WHERE c.site_id = ?
                     ORDER BY f.nom, n.ordre";
    $classe_stmt = $db->prepare($classe_query);
    $classe_stmt->execute([$site_id]);
    $classes_list = $classe_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les filières
    $filiere_query = "SELECT * FROM filieres ORDER BY nom";
    $filiere_stmt = $db->prepare($filiere_query);
    $filiere_stmt->execute();
    $filieres = $filiere_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les niveaux
    $niveau_query = "SELECT * FROM niveaux ORDER BY ordre";
    $niveau_stmt = $db->prepare($niveau_query);
    $niveau_stmt->execute();
    $niveaux = $niveau_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI DAC</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <style>
    .student-avatar {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #007bff;
    }
    
    .student-card {
        border-left: 4px solid #007bff;
        transition: all 0.3s;
    }
    
    .student-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .stat-card {
        text-align: center;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 15px;
        color: white;
    }
    
    .stat-actif { background-color: #28a745; }
    .stat-inactif { background-color: #6c757d; }
    .stat-diplome { background-color: #17a2b8; }
    .stat-abandonne { background-color: #dc3545; }
    
    .badge-statut {
        padding: 5px 10px;
        border-radius: 20px;
        font-weight: 500;
    }
    
    .badge-actif { background-color: #d4edda; color: #155724; }
    .badge-inactif { background-color: #e2e3e5; color: #383d41; }
    .badge-diplome { background-color: #d1ecf1; color: #0c5460; }
    .badge-abandonne { background-color: #f8d7da; color: #721c24; }
    
    .tab-content {
        padding: 20px;
        border: 1px solid #dee2e6;
        border-top: none;
        border-radius: 0 0 10px 10px;
    }
    
    .document-badge {
        display: inline-block;
        padding: 8px 12px;
        margin: 5px;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .document-badge:hover {
        background: #e9ecef;
        transform: translateY(-1px);
    }
    
    .document-present { border-color: #28a745; }
    .document-missing { border-color: #dc3545; }
    
    .avatar-placeholder {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: #e9ecef;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6c757d;
        margin: 0 auto;
    }
    
    .form-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-graduation-cap"></i> ISGI - DAC
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="etudiants.php">
                            <i class="fas fa-user-graduate"></i> Étudiants
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cartes_etudiant.php">
                            <i class="fas fa-id-card"></i> Cartes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="presences.php">
                            <i class="fas fa-calendar-check"></i> Présences
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo ROOT_PATH; ?>/auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Déconnexion
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid mt-3">
        <div class="row">
            <!-- Sidebar simplifiée -->
            <div class="col-md-3 col-lg-2 d-none d-md-block">
                <div class="list-group">
                    <a href="dashboard.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a href="etudiants.php" class="list-group-item list-group-item-action active">
                        <i class="fas fa-user-graduate me-2"></i>Étudiants
                    </a>
                    <a href="cartes_etudiant.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-id-card me-2"></i>Cartes étudiant
                    </a>
                    <a href="presences.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-calendar-check me-2"></i>Présences
                    </a>
                    <a href="calendrier_academique.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-calendar me-2"></i>Calendrier
                    </a>
                    <a href="notes.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-file-alt me-2"></i>Notes
                    </a>
                    <a href="bulletins.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-file-certificate me-2"></i>Bulletins
                    </a>
                </div>
            </div>
            
            <!-- Contenu principal -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-user-graduate"></i> Gestion des Étudiants
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <?php if($action == 'list'): ?>
                        <a href="etudiants.php?action=create" class="btn btn-success">
                            <i class="fas fa-plus"></i> Nouvel étudiant
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Messages -->
                <?php if(isset($message)): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if(isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Contenu selon l'action -->
                <?php switch($action): 
                    case 'view': ?>
                        <!-- Vue détaillée d'un étudiant -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body text-center">
                                        <?php if(!empty($etudiant['photo_identite'])): ?>
                                        <img src="<?php echo ROOT_PATH . '/' . htmlspecialchars($etudiant['photo_identite']); ?>" 
                                             class="student-avatar mb-3" alt="Photo">
                                        <?php else: ?>
                                        <div class="avatar-placeholder mb-3">
                                            <i class="fas fa-user fa-3x"></i>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <h4><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></h4>
                                        <p class="text-muted mb-1"><?php echo htmlspecialchars($etudiant['matricule']); ?></p>
                                        
                                        <?php echo getStatusBadge($etudiant['statut']); ?>
                                        
                                        <hr>
                                        
                                        <div class="row text-start">
                                            <div class="col-6">
                                                <small><strong>Date naissance:</strong></small><br>
                                                <small><?php echo formatDateFr($etudiant['date_naissance']); ?></small>
                                            </div>
                                            <div class="col-6">
                                                <small><strong>Lieu naissance:</strong></small><br>
                                                <small><?php echo htmlspecialchars($etudiant['lieu_naissance']); ?></small>
                                            </div>
                                        </div>
                                        
                                        <hr>
                                        
                                        <div class="d-grid gap-2">
                                            <a href="etudiants.php?action=edit&id=<?php echo $etudiant['id']; ?>" 
                                               class="btn btn-warning">
                                                <i class="fas fa-edit"></i> Modifier
                                            </a>
                                            <a href="cartes_etudiant.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                               class="btn btn-info">
                                                <i class="fas fa-id-card"></i> Générer carte
                                            </a>
                                            <button class="btn btn-danger" data-bs-toggle="modal" 
                                                    data-bs-target="#deleteModal<?php echo $etudiant['id']; ?>">
                                                <i class="fas fa-trash"></i> Désactiver
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Documents -->
                                <div class="card mt-4">
                                    <div class="card-header">
                                        <h5 class="mb-0">Documents</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php 
                                        $documents = [
                                            'photo_identite' => 'Photo d\'identité',
                                            'acte_naissance' => 'Acte de naissance',
                                            'releve_notes' => 'Relevé de notes',
                                            'attestation_legalisee' => 'Attestation légalisée'
                                        ];
                                        
                                        foreach($documents as $field => $label):
                                            $has_document = !empty($etudiant[$field]);
                                            $class = $has_document ? 'document-present' : 'document-missing';
                                        ?>
                                        <div class="document-badge <?php echo $class; ?>">
                                            <i class="fas fa-<?php echo $has_document ? 'check text-success' : 'times text-danger'; ?>"></i>
                                            <?php echo htmlspecialchars($label); ?>
                                            
                                            <?php if($has_document): ?>
                                            <br>
                                            <a href="<?php echo ROOT_PATH . '/' . htmlspecialchars($etudiant[$field]); ?>" 
                                               target="_blank" class="btn btn-sm btn-outline-primary mt-1">
                                                <i class="fas fa-eye"></i> Voir
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <!-- Onglets -->
                                <ul class="nav nav-tabs" id="studentTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info">
                                            Informations
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic">
                                            Scolarité
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="presence-tab" data-bs-toggle="tab" data-bs-target="#presence">
                                            Présences
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment">
                                            Paiements
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes">
                                            Notes
                                        </button>
                                    </li>
                                </ul>
                                
                                <div class="tab-content" id="studentTabsContent">
                                    <!-- Onglet Informations -->
                                    <div class="tab-pane fade show active" id="info">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5>Informations personnelles</h5>
                                                <div class="row mt-3">
                                                    <div class="col-md-6">
                                                        <p><strong>CNI:</strong> <?php echo htmlspecialchars($etudiant['numero_cni']); ?></p>
                                                        <p><strong>Sexe:</strong> <?php echo $etudiant['sexe'] == 'M' ? 'Masculin' : 'Féminin'; ?></p>
                                                        <p><strong>Nationalité:</strong> <?php echo htmlspecialchars($etudiant['nationalite']); ?></p>
                                                        <p><strong>Adresse:</strong> <?php echo htmlspecialchars($etudiant['adresse']); ?></p>
                                                        <p><strong>Ville:</strong> <?php echo htmlspecialchars($etudiant['ville']); ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p><strong>Profession:</strong> <?php echo htmlspecialchars($etudiant['profession'] ?: 'Non spécifié'); ?></p>
                                                        <p><strong>Situation matrimoniale:</strong> <?php echo htmlspecialchars($etudiant['situation_matrimoniale'] ?: 'Non spécifié'); ?></p>
                                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($etudiant['email'] ?? 'Non défini'); ?></p>
                                                        <p><strong>Téléphone:</strong> <?php echo htmlspecialchars($etudiant['user_phone'] ?? 'Non défini'); ?></p>
                                                        <p><strong>Date inscription:</strong> <?php echo formatDateFr($etudiant['date_inscription']); ?></p>
                                                    </div>
                                                </div>
                                                
                                                <!-- Famille -->
                                                <h5 class="mt-4">Informations familiales</h5>
                                                <div class="row mt-3">
                                                    <div class="col-md-6">
                                                        <p><strong>Père:</strong> <?php echo htmlspecialchars($etudiant['nom_pere'] ?: 'Non spécifié'); ?></p>
                                                        <p><strong>Profession père:</strong> <?php echo htmlspecialchars($etudiant['profession_pere'] ?: 'Non spécifié'); ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p><strong>Mère:</strong> <?php echo htmlspecialchars($etudiant['nom_mere'] ?: 'Non spécifié'); ?></p>
                                                        <p><strong>Profession mère:</strong> <?php echo htmlspecialchars($etudiant['profession_mere'] ?: 'Non spécifié'); ?></p>
                                                    </div>
                                                </div>
                                                
                                                <!-- Tuteur -->
                                                <?php if(!empty($etudiant['nom_tuteur'])): ?>
                                                <h5 class="mt-4">Tuteur</h5>
                                                <div class="row mt-3">
                                                    <div class="col-md-6">
                                                        <p><strong>Nom:</strong> <?php echo htmlspecialchars($etudiant['nom_tuteur']); ?></p>
                                                        <p><strong>Profession:</strong> <?php echo htmlspecialchars($etudiant['profession_tuteur'] ?: 'Non spécifié'); ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p><strong>Téléphone:</strong> <?php echo htmlspecialchars($etudiant['telephone_tuteur'] ?: 'Non spécifié'); ?></p>
                                                        <p><strong>Lieu service:</strong> <?php echo htmlspecialchars($etudiant['lieu_service_tuteur'] ?: 'Non spécifié'); ?></p>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Onglet Scolarité -->
                                    <div class="tab-pane fade" id="academic">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5>Informations académiques</h5>
                                                <div class="row mt-3">
                                                    <div class="col-md-6">
                                                        <p><strong>Classe:</strong> <?php echo htmlspecialchars($etudiant['classe_nom'] ?: 'Non assigné'); ?></p>
                                                        <p><strong>Filière:</strong> <?php echo htmlspecialchars($etudiant['filiere_nom'] ?: 'Non assigné'); ?></p>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <p><strong>Niveau:</strong> <?php echo htmlspecialchars($etudiant['niveau_libelle'] ?: 'Non assigné'); ?></p>
                                                        <p><strong>Site:</strong> <?php echo htmlspecialchars($etudiant['site_nom']); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Onglet Présences -->
                                    <div class="tab-pane fade" id="presence">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5>Historique des présences</h5>
                                                <?php if(!empty($presences)): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Date</th>
                                                                <th>Heure</th>
                                                                <th>Matière</th>
                                                                <th>Type</th>
                                                                <th>Statut</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach($presences as $pres): ?>
                                                            <tr>
                                                                <td><?php echo !empty($pres['date_heure']) ? date('d/m/Y', strtotime($pres['date_heure'])) : ''; ?></td>
                                                                <td><?php echo !empty($pres['date_heure']) ? date('H:i', strtotime($pres['date_heure'])) : ''; ?></td>
                                                                <td><?php echo htmlspecialchars($pres['matiere_nom'] ?? 'Entrée/Sortie'); ?></td>
                                                                <td><?php echo htmlspecialchars($pres['type_presence'] ?? ''); ?></td>
                                                                <td>
                                                                    <?php echo getStatusBadge($pres['statut'] ?? ''); ?>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php else: ?>
                                                <div class="alert alert-info">
                                                    Aucune présence enregistrée
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Onglet Paiements -->
                                    <div class="tab-pane fade" id="payment">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5>Historique des paiements</h5>
                                                <?php if(!empty($paiements)): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Date</th>
                                                                <th>Type frais</th>
                                                                <th>Montant</th>
                                                                <th>Mode</th>
                                                                <th>Référence</th>
                                                                <th>Statut</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach($paiements as $paiement): ?>
                                                            <tr>
                                                                <td><?php echo formatDateFr($paiement['date_paiement']); ?></td>
                                                                <td><?php echo htmlspecialchars($paiement['type_frais']); ?></td>
                                                                <td><?php echo formatMoney($paiement['montant']); ?></td>
                                                                <td><?php echo htmlspecialchars($paiement['mode_paiement']); ?></td>
                                                                <td><?php echo htmlspecialchars($paiement['reference']); ?></td>
                                                                <td>
                                                                    <?php echo getStatusBadge($paiement['statut']); ?>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php else: ?>
                                                <div class="alert alert-info">
                                                    Aucun paiement enregistré
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Onglet Notes -->
                                    <div class="tab-pane fade" id="notes">
                                        <div class="card">
                                            <div class="card-body">
                                                <h5>Notes récentes</h5>
                                                <?php if(!empty($notes)): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm">
                                                        <thead>
                                                            <tr>
                                                                <th>Matière</th>
                                                                <th>Type examen</th>
                                                                <th>Note</th>
                                                                <th>Date</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach($notes as $note): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($note['matiere_nom']); ?></td>
                                                                <td><?php echo htmlspecialchars($note['type_examen']); ?></td>
                                                                <td>
                                                                    <span class="badge bg-<?php 
                                                                    $note_value = $note['note'];
                                                                    if($note_value >= 10) echo 'success';
                                                                    elseif($note_value >= 8) echo 'warning';
                                                                    else echo 'danger';
                                                                    ?>">
                                                                        <?php echo number_format($note_value, 2); ?>/20
                                                                    </span>
                                                                </td>
                                                                <td><?php echo formatDateFr($note['date_evaluation']); ?></td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <?php else: ?>
                                                <div class="alert alert-info">
                                                    Aucune note disponible
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Modal de suppression -->
                        <div class="modal fade" id="deleteModal<?php echo $etudiant['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Confirmer la désactivation</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="etudiant_id" value="<?php echo $etudiant['id']; ?>">
                                            <p>Êtes-vous sûr de vouloir désactiver l'étudiant <strong><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></strong> ?</p>
                                            <p class="text-danger">L'étudiant ne pourra plus se connecter au système.</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                            <button type="submit" name="delete_etudiant" class="btn btn-danger">Confirmer</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php break; ?>
                    
                    <?php case 'edit': 
                    case 'create': ?>
                        <!-- Formulaire de création/modification -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <?php echo $action == 'create' ? 'Nouvel étudiant' : 'Modifier étudiant'; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data" id="studentForm">
                                    <?php if($action == 'edit'): ?>
                                    <input type="hidden" name="etudiant_id" value="<?php echo $etudiant['id']; ?>">
                                    <?php endif; ?>
                                    
                                    <!-- Onglets du formulaire -->
                                    <ul class="nav nav-tabs mb-4" id="formTabs" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal">
                                                Informations personnelles
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="family-tab" data-bs-toggle="tab" data-bs-target="#family">
                                                Informations familiales
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic">
                                                Scolarité
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents">
                                                Documents
                                            </button>
                                        </li>
                                    </ul>
                                    
                                    <div class="tab-content" id="formTabsContent">
                                        <!-- Onglet Informations personnelles -->
                                        <div class="tab-pane fade show active" id="personal">
                                            <div class="form-section">
                                                <h6>Informations de base</h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nom *</label>
                                                            <input type="text" name="nom" class="form-control" required
                                                                   value="<?php echo htmlspecialchars($etudiant['nom'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Prénom *</label>
                                                            <input type="text" name="prenom" class="form-control" required
                                                                   value="<?php echo htmlspecialchars($etudiant['prenom'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Numéro CNI *</label>
                                                            <input type="text" name="numero_cni" class="form-control" required
                                                                   value="<?php echo htmlspecialchars($etudiant['numero_cni'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Date de naissance *</label>
                                                            <input type="date" name="date_naissance" class="form-control" required
                                                                   value="<?php echo htmlspecialchars($etudiant['date_naissance'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Lieu de naissance *</label>
                                                            <input type="text" name="lieu_naissance" class="form-control" required
                                                                   value="<?php echo htmlspecialchars($etudiant['lieu_naissance'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="form-section">
                                                <h6>Détails personnels</h6>
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <div class="mb-3">
                                                            <label class="form-label">Sexe *</label>
                                                            <select name="sexe" class="form-select" required>
                                                                <option value="M" <?php echo ($etudiant['sexe'] ?? '') == 'M' ? 'selected' : ''; ?>>Masculin</option>
                                                                <option value="F" <?php echo ($etudiant['sexe'] ?? '') == 'F' ? 'selected' : ''; ?>>Féminin</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nationalité</label>
                                                            <input type="text" name="nationalite" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['nationalite'] ?? 'Congolaise'); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="mb-3">
                                                            <label class="form-label">Situation matrimoniale</label>
                                                            <select name="situation_matrimoniale" class="form-select">
                                                                <option value="">Non spécifié</option>
                                                                <option value="Celibataire" <?php echo ($etudiant['situation_matrimoniale'] ?? '') == 'Celibataire' ? 'selected' : ''; ?>>Célibataire</option>
                                                                <option value="Marie" <?php echo ($etudiant['situation_matrimoniale'] ?? '') == 'Marie' ? 'selected' : ''; ?>>Marié(e)</option>
                                                                <option value="Divorce" <?php echo ($etudiant['situation_matrimoniale'] ?? '') == 'Divorce' ? 'selected' : ''; ?>>Divorcé(e)</option>
                                                                <option value="Veuf" <?php echo ($etudiant['situation_matrimoniale'] ?? '') == 'Veuf' ? 'selected' : ''; ?>>Veuf/Veuve</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="mb-3">
                                                            <label class="form-label">Profession</label>
                                                            <input type="text" name="profession" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['profession'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="form-section">
                                                <h6>Adresse et contact</h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Adresse *</label>
                                                            <textarea name="adresse" class="form-control" rows="2" required><?php echo htmlspecialchars($etudiant['adresse'] ?? ''); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="mb-3">
                                                            <label class="form-label">Ville *</label>
                                                            <input type="text" name="ville" class="form-control" required
                                                                   value="<?php echo htmlspecialchars($etudiant['ville'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <div class="mb-3">
                                                            <label class="form-label">Pays</label>
                                                            <input type="text" name="pays" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['pays'] ?? 'Congo'); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" name="email" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['email'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Téléphone</label>
                                                            <input type="text" name="telephone" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['user_phone'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="mb-3">
                                                            <label class="form-label">Statut</label>
                                                            <select name="statut" class="form-select">
                                                                <option value="actif" <?php echo ($etudiant['statut'] ?? 'actif') == 'actif' ? 'selected' : ''; ?>>Actif</option>
                                                                <option value="inactif" <?php echo ($etudiant['statut'] ?? '') == 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                                                                <option value="diplome" <?php echo ($etudiant['statut'] ?? '') == 'diplome' ? 'selected' : ''; ?>>Diplômé</option>
                                                                <option value="abandonne" <?php echo ($etudiant['statut'] ?? '') == 'abandonne' ? 'selected' : ''; ?>>Abandonné</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Onglet Informations familiales -->
                                        <div class="tab-pane fade" id="family">
                                            <div class="form-section">
                                                <h6>Parents</h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nom du père</label>
                                                            <input type="text" name="nom_pere" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['nom_pere'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Profession du père</label>
                                                            <input type="text" name="profession_pere" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['profession_pere'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nom de la mère</label>
                                                            <input type="text" name="nom_mere" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['nom_mere'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Profession de la mère</label>
                                                            <input type="text" name="profession_mere" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['profession_mere'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Téléphone des parents</label>
                                                            <input type="text" name="telephone_parent" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['telephone_parent'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="form-section">
                                                <h6>Tuteur (si différent des parents)</h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Nom du tuteur</label>
                                                            <input type="text" name="nom_tuteur" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['nom_tuteur'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Profession du tuteur</label>
                                                            <input type="text" name="profession_tuteur" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['profession_tuteur'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Téléphone du tuteur</label>
                                                            <input type="text" name="telephone_tuteur" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['telephone_tuteur'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Lieu de service</label>
                                                            <input type="text" name="lieu_service_tuteur" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['lieu_service_tuteur'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Onglet Scolarité -->
                                        <div class="tab-pane fade" id="academic">
                                            <div class="form-section">
                                                <h6>Informations académiques</h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Classe</label>
                                                            <select name="classe_id" class="form-select">
                                                                <option value="">Non assigné</option>
                                                                <?php foreach($classes_list as $classe): ?>
                                                                <option value="<?php echo $classe['id']; ?>"
                                                                        <?php echo ($etudiant['classe_id'] ?? '') == $classe['id'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($classe['filiere_nom'] . ' - ' . $classe['niveau_libelle']); ?>
                                                                </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Date d'inscription</label>
                                                            <input type="date" name="date_inscription" class="form-control"
                                                                   value="<?php echo htmlspecialchars($etudiant['date_inscription'] ?? date('Y-m-d')); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Onglet Documents -->
                                        <div class="tab-pane fade" id="documents">
                                            <div class="form-section">
                                                <h6>Documents à télécharger</h6>
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Photo d'identité</label>
                                                            <input type="file" name="photo_identite" class="form-control" accept="image/*">
                                                            <?php if(!empty($etudiant['photo_identite'])): ?>
                                                            <small class="text-muted">Fichier actuel: <?php echo basename($etudiant['photo_identite']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Acte de naissance</label>
                                                            <input type="file" name="acte_naissance" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                                            <?php if(!empty($etudiant['acte_naissance'])): ?>
                                                            <small class="text-muted">Fichier actuel: <?php echo basename($etudiant['acte_naissance']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Relevé de notes</label>
                                                            <input type="file" name="releve_notes" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                                            <?php if(!empty($etudiant['releve_notes'])): ?>
                                                            <small class="text-muted">Fichier actuel: <?php echo basename($etudiant['releve_notes']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label">Attestation légalisée</label>
                                                            <input type="file" name="attestation_legalisee" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                                            <?php if(!empty($etudiant['attestation_legalisee'])): ?>
                                                            <small class="text-muted">Fichier actuel: <?php echo basename($etudiant['attestation_legalisee']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="text-end mt-4">
                                        <a href="etudiants.php" class="btn btn-secondary">Annuler</a>
                                        <button type="submit" name="<?php echo $action == 'create' ? 'create_etudiant' : 'update_etudiant'; ?>" 
                                                class="btn btn-primary">
                                            <i class="fas fa-save"></i> 
                                            <?php echo $action == 'create' ? 'Créer l\'étudiant' : 'Mettre à jour'; ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php break; ?>
                    
                    <?php default: ?>
                        <!-- Liste des étudiants -->
                        <!-- Statistiques -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="card stat-card stat-actif">
                                    <?php 
                                    try {
                                        $actif_query = "SELECT COUNT(*) FROM etudiants WHERE site_id = ? AND statut = 'actif'";
                                        $actif_stmt = $db->prepare($actif_query);
                                        $actif_stmt->execute([$site_id]);
                                        $actif_count = $actif_stmt->fetchColumn();
                                    } catch (Exception $e) {
                                        $actif_count = 0;
                                    }
                                    ?>
                                    <h4><?php echo $actif_count; ?></h4>
                                    <p class="mb-0">Étudiants actifs</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card stat-inactif">
                                    <?php 
                                    try {
                                        $inactif_query = "SELECT COUNT(*) FROM etudiants WHERE site_id = ? AND statut = 'inactif'";
                                        $inactif_stmt = $db->prepare($inactif_query);
                                        $inactif_stmt->execute([$site_id]);
                                        $inactif_count = $inactif_stmt->fetchColumn();
                                    } catch (Exception $e) {
                                        $inactif_count = 0;
                                    }
                                    ?>
                                    <h4><?php echo $inactif_count; ?></h4>
                                    <p class="mb-0">Étudiants inactifs</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card stat-diplome">
                                    <?php 
                                    try {
                                        $diplome_query = "SELECT COUNT(*) FROM etudiants WHERE site_id = ? AND statut = 'diplome'";
                                        $diplome_stmt = $db->prepare($diplome_query);
                                        $diplome_stmt->execute([$site_id]);
                                        $diplome_count = $diplome_stmt->fetchColumn();
                                    } catch (Exception $e) {
                                        $diplome_count = 0;
                                    }
                                    ?>
                                    <h4><?php echo $diplome_count; ?></h4>
                                    <p class="mb-0">Diplômés</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stat-card stat-abandonne">
                                    <?php 
                                    try {
                                        $abandon_query = "SELECT COUNT(*) FROM etudiants WHERE site_id = ? AND statut = 'abandonne'";
                                        $abandon_stmt = $db->prepare($abandon_query);
                                        $abandon_stmt->execute([$site_id]);
                                        $abandon_count = $abandon_stmt->fetchColumn();
                                    } catch (Exception $e) {
                                        $abandon_count = 0;
                                    }
                                    ?>
                                    <h4><?php echo $abandon_count; ?></h4>
                                    <p class="mb-0">Abandons</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Barre de recherche et filtres -->
                        <div class="card mb-4">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Recherche</label>
                                        <div class="input-group">
                                            <input type="text" name="search" class="form-control" 
                                                   placeholder="Matricule, Nom, Prénom, CNI..." value="<?php echo htmlspecialchars($search); ?>">
                                            <button class="btn btn-outline-secondary" type="submit">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Classe</label>
                                        <select name="classe_id" class="form-select">
                                            <option value="">Toutes les classes</option>
                                            <?php foreach($classes_list as $classe): ?>
                                            <option value="<?php echo $classe['id']; ?>" 
                                                    <?php echo $classe_id == $classe['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($classe['filiere_nom'] . ' - ' . $classe['niveau_libelle']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3">
                                        <label class="form-label">Statut</label>
                                        <select name="statut" class="form-select">
                                            <option value="">Tous les statuts</option>
                                            <option value="actif" <?php echo $statut == 'actif' ? 'selected' : ''; ?>>Actif</option>
                                            <option value="inactif" <?php echo $statut == 'inactif' ? 'selected' : ''; ?>>Inactif</option>
                                            <option value="diplome" <?php echo $statut == 'diplome' ? 'selected' : ''; ?>>Diplômé</option>
                                            <option value="abandonne" <?php echo $statut == 'abandonne' ? 'selected' : ''; ?>>Abandonné</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-filter"></i> Filtrer
                                        </button>
                                    </div>
                                </form>
                                
                                <div class="mt-3">
                                    <a href="etudiants.php" class="btn btn-outline-secondary">Réinitialiser</a>
                                    <a href="etudiants.php?action=create" class="btn btn-success">
                                        <i class="fas fa-plus"></i> Nouvel étudiant
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Liste des étudiants -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Liste des étudiants (<?php echo $total_count; ?>)</h5>
                                <div>
                                    <?php if($total_pages > 1): ?>
                                    <small class="text-muted">Page <?php echo $page; ?> sur <?php echo $total_pages; ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if(empty($etudiants)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> Aucun étudiant trouvé
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Matricule</th>
                                                <th>Nom & Prénom</th>
                                                <th>CNI</th>
                                                <th>Classe</th>
                                                <th>Filière</th>
                                                <th>Statut</th>
                                                <th>Date inscription</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($etudiants as $etudiant): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($etudiant['matricule']); ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($etudiant['prenom'] . ' ' . $etudiant['nom']); ?></strong>
                                                    <?php if(!empty($etudiant['date_naissance'])): ?>
                                                    <br>
                                                    <small class="text-muted">Né(e) le: <?php echo formatDateFr($etudiant['date_naissance']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($etudiant['numero_cni']); ?></td>
                                                <td><?php echo htmlspecialchars($etudiant['classe_nom'] ?? 'Non assigné'); ?></td>
                                                <td><?php echo htmlspecialchars($etudiant['filiere_nom'] ?? 'Non assigné'); ?></td>
                                                <td>
                                                    <?php 
                                                    $statut_badge = 'badge-' . ($etudiant['statut'] ?? 'inactif');
                                                    echo '<span class="badge-statut ' . $statut_badge . '">' . ucfirst($etudiant['statut'] ?? 'inactif') . '</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    if (!empty($etudiant['date_inscription'])) {
                                                        echo formatDateFr($etudiant['date_inscription']);
                                                    } else {
                                                        echo 'Non défini';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="etudiants.php?action=view&id=<?php echo $etudiant['id']; ?>" 
                                                           class="btn btn-info" title="Voir">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="etudiants.php?action=edit&id=<?php echo $etudiant['id']; ?>" 
                                                           class="btn btn-warning" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="cartes_etudiant.php?etudiant_id=<?php echo $etudiant['id']; ?>" 
                                                           class="btn btn-primary" title="Carte">
                                                            <i class="fas fa-id-card"></i>
                                                        </a>
                                                        <button class="btn btn-danger" title="Désactiver"
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#deleteModal<?php echo $etudiant['id']; ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                    
                                                    <!-- Modal de suppression -->
                                                    <div class="modal fade" id="deleteModal<?php echo $etudiant['id']; ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-sm">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title">Confirmer</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <form method="POST">
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="etudiant_id" value="<?php echo $etudiant['id']; ?>">
                                                                        <p class="text-center">Désactiver cet étudiant ?</p>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Non</button>
                                                                        <button type="submit" name="delete_etudiant" class="btn btn-danger">Oui</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if($total_pages > 1): ?>
                                <nav aria-label="Pagination">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&classe_id=<?php echo $classe_id; ?>&statut=<?php echo $statut; ?>">
                                                <i class="fas fa-chevron-left"></i>
                                            </a>
                                        </li>
                                        
                                        <?php 
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($total_pages, $page + 2);
                                        
                                        for($p = $start_page; $p <= $end_page; $p++): 
                                        ?>
                                        <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&classe_id=<?php echo $classe_id; ?>&statut=<?php echo $statut; ?>">
                                                <?php echo $p; ?>
                                            </a>
                                        </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&classe_id=<?php echo $classe_id; ?>&statut=<?php echo $statut; ?>">
                                                <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                                <?php endif; ?>
                                
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php break; ?>
                <?php endswitch; ?>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <script>
    // Validation du formulaire
    document.getElementById('studentForm')?.addEventListener('submit', function(e) {
        const requiredFields = this.querySelectorAll('[required]');
        let valid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                valid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        if (!valid) {
            e.preventDefault();
            alert('Veuillez remplir tous les champs obligatoires (*)');
        }
    });
    
    // Changement d'onglet
    const formTabs = document.querySelectorAll('#formTabs button');
    formTabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            formTabs.forEach(t => t.classList.remove('active'));
            e.target.classList.add('active');
        });
    });
    
    // Auto-focus sur le champ de recherche
    document.querySelector('input[name="search"]')?.focus();
    
    // Confirmation avant suppression
    const deleteButtons = document.querySelectorAll('button[name="delete_etudiant"]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm('Êtes-vous sûr de vouloir désactiver cet étudiant ?')) {
                e.preventDefault();
            }
        });
    });
    </script>
</body>
</html>