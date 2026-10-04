<?php
// dashboard/admin_principal/demandes.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier le rôle (doit être administrateur principal)
if ($_SESSION['role_id'] != 1) { // 1 = Administrateur principal
    header('Location: ' . ROOT_PATH . '/dashboard/access_denied.php');
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

/**
 * Formate une taille de fichier en octets en format lisible
 */
function formatTaille($bytes, $precision = 2) {
    if ($bytes == 0 || $bytes === null) {
        return '0 o';
    }
    
    $units = array('o', 'Ko', 'Mo', 'Go', 'To');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Envoyer un email de notification avec PHPMailer
 */
function envoyerEmail($to, $subject, $body) {
    try {
        // Charger PHPMailer
        require_once ROOT_PATH . '/vendor/autoload.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuration SMTP
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'moundouroger@gmail.com';
        $mail->Password   = 'gsfesfcvqwqbkxic';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('noreply@isgi.cg', 'ISGI - Plateforme Académique');
        $mail->addReplyTo('support@isgi.cg', 'Support ISGI');
        $mail->addAddress($to);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Template HTML
        $html_body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
                .container { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: #0066cc; color: white; padding: 25px 20px; text-align: center; }
                .content { padding: 30px; }
                .button { display: inline-block; background: #0066cc; color: white !important; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 15px 0; font-weight: bold; }
                .info-box { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 4px; margin: 20px 0; }
                .warning-box { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #dee2e6; color: #6c757d; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>ISGI - Plateforme Académique</h2>
                </div>
                <div class="content">
                    ' . nl2br(htmlspecialchars($body)) . '
                </div>
                <div class="footer">
                    <p><strong>Institut Supérieur de Gestion et d\'Ingénierie</strong></p>
                    <p>© ' . date('Y') . ' ISGI Congo. Tous droits réservés.</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $html_body;
        $mail->AltBody = strip_tags($body);
        
        if ($mail->send()) {
            error_log("✅ EMAIL ENVOYÉ: À $to | Sujet: $subject");
            return true;
        } else {
            error_log("❌ ERREUR PHPMailer: " . $mail->ErrorInfo);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("❌ EXCEPTION PHPMailer: " . $e->getMessage());
        return false;
    }
}

// Fonction pour obtenir l'adresse d'un site
function getSiteAddress($site_id) {
    $addresses = [
        1 => "Quartier Poto-Poto, Avenue de France, Brazzaville",
        2 => "Quartier Mpita-Socoprise, Arrêt OCI, Pointe-Noire",
        3 => "Centre-ville, en diagonale de la CNSS, Ouesso"
    ];
    
    return $addresses[$site_id] ?? "Adresse non spécifiée";
}

// Construire l'email d'approbation (sans détails de paiement)
function buildApprovalEmail($demande) {
    return "Félicitations " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n" .
           "Votre demande d'inscription à l'ISGI a été approuvée avec succès !\n\n" .
           "📋 <strong>Numéro de dossier: " . $demande['numero_demande'] . "</strong>\n\n" .
           "✅ <strong>Votre inscription est validée</strong>\n\n" .
           "📚 <strong>Informations importantes:</strong>\n" .
           "• Filière: " . $demande['filiere'] . " (" . $demande['niveau'] . ")\n" .
           "• Site: " . $demande['site_formation'] . "\n" .
           "• Rentrée: " . $demande['type_rentree'] . "\n\n" .
           "📅 <strong>Prochaines étapes:</strong>\n" .
           "• Vous serez contacté pour la création de votre compte étudiant\n" .
           "• Vous recevrez votre matricule étudiant\n" .
           "• La rentrée aura lieu selon le calendrier académique\n\n" .
           "Nous sommes impatients de vous accueillir à l'ISGI !\n\n" .
           "Cordialement,\nL'équipe ISGI Congo";
}

// Construire l'email de validation (création de l'étudiant)
function buildValidationEmail($demande, $matricule) {
    return "Félicitations " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n" .
           "Votre inscription à l'ISGI est maintenant complète et vous êtes officiellement étudiant(e) !\n\n" .
           "🎓 <strong>Votre matricule étudiant: " . $matricule . "</strong>\n\n" .
           "📚 <strong>Informations importantes:</strong>\n" .
           "• Filière: " . $demande['filiere'] . " (" . $demande['niveau'] . ")\n" .
           "• Site: " . $demande['site_formation'] . "\n" .
           "• Rentrée: " . $demande['type_rentree'] . "\n\n" .
           "📅 <strong>Prochaines étapes:</strong>\n" .
           "1. Vous recevrez bientôt votre emploi du temps\n" .
           "2. La rentrée aura lieu selon le calendrier académique\n" .
           "3. Conservez précieusement votre matricule\n\n" .
           "Bienvenue dans la famille ISGI !\n\n" .
           "Cordialement,\nL'équipe ISGI Congo";
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    $pageTitle = "Demandes d'Inscription - Administrateur Principal";
    
    function formatDateFr($date, $format = 'd/m/Y') {
        if (empty($date) || $date == '0000-00-00') return '';
        $timestamp = strtotime($date);
        if ($timestamp === false) return '';
        return date($format, $timestamp);
    }
    
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'en_attente': return '<span class="badge bg-warning">En attente</span>';
            case 'en_traitement': return '<span class="badge bg-info">En traitement</span>';
            case 'validee': return '<span class="badge bg-success">Validée</span>';
            case 'rejetee': return '<span class="badge bg-danger">Rejetée</span>';
            case 'approuvee': return '<span class="badge bg-primary">Approuvée</span>';
            default: return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    class SessionManager {
        public static function getUserName() {
            return isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Utilisateur';
        }
        public static function getRoleId() {
            return isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;
        }
        public static function getSiteId() {
            return isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
        }
        public static function getUserId() {
            return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        }
    }
    
    // Récupérer les paramètres de filtrage
    $statut_filter = isset($_GET['statut']) ? $_GET['statut'] : 'en_attente';
    $site_filter = isset($_GET['site']) ? $_GET['site'] : '';
    $date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
    $date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    
    // Construire la requête avec filtres
    $where_conditions = array();
    $params = array();

    if (!empty($statut_filter) && $statut_filter != 'tous') {
        if (strpos($statut_filter, ',') !== false) {
            $statuts = explode(',', $statut_filter);
            $placeholders = rtrim(str_repeat('?,', count($statuts)), ',');
            $where_conditions[] = "d.statut IN ($placeholders)";
            $params = array_merge($params, $statuts);
        } else {
            $where_conditions[] = "d.statut = ?";
            $params[] = $statut_filter;
        }
    }
    
    if (!empty($site_filter)) {
        $where_conditions[] = "d.site_id = ?";
        $params[] = $site_filter;
    }
    
    if (!empty($date_debut)) {
        $where_conditions[] = "DATE(d.date_demande) >= ?";
        $params[] = $date_debut;
    }
    
    if (!empty($date_fin)) {
        $where_conditions[] = "DATE(d.date_demande) <= ?";
        $params[] = $date_fin;
    }
    
    if (!empty($search)) {
        $where_conditions[] = "(d.nom LIKE ? OR d.prenom LIKE ? OR d.email LIKE ? OR d.numero_demande LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Récupérer les demandes
    $query = "SELECT d.*, s.nom as site_nom, s.ville as site_ville,
              CONCAT(uv.nom, ' ', uv.prenom) as validateur_nom,
              CONCAT(ua.nom, ' ', ua.prenom) as admin_traitant_nom
              FROM demande_inscriptions d
              LEFT JOIN sites s ON d.site_id = s.id
              LEFT JOIN utilisateurs uv ON d.validee_par = uv.id
              LEFT JOIN utilisateurs ua ON d.admin_traitant_id = ua.id
              $where_clause
              ORDER BY d.date_demande DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les sites
    $sites_query = "SELECT id, nom, ville FROM sites WHERE statut = 'actif' ORDER BY ville";
    $sites = $db->query($sites_query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Compter les demandes par statut
    $stats_query = "SELECT statut, COUNT(*) as count FROM demande_inscriptions GROUP BY statut";
    $stats_result = $db->query($stats_query)->fetchAll(PDO::FETCH_ASSOC);
    
    $stats = array(
        'en_attente' => 0, 'en_traitement' => 0, 'validee' => 0, 
        'rejetee' => 0, 'approuvee' => 0, 'total' => 0
    );
    
    foreach ($stats_result as $row) {
        $stats[$row['statut']] = $row['count'];
        $stats['total'] += $row['count'];
    }
    
    // Traitement des actions
    $message = '';
    $message_type = '';
    
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $action = $_POST['action'] ?? '';
        $demande_id = $_POST['demande_id'] ?? 0;
        $commentaire = $_POST['commentaire'] ?? '';
        $raison_rejet = $_POST['raison_rejet'] ?? '';
        
        if ($action && $demande_id) {
            try {
                $db->beginTransaction();
                
                $demande_query = "SELECT * FROM demande_inscriptions WHERE id = ?";
                $demande_stmt = $db->prepare($demande_query);
                $demande_stmt->execute([$demande_id]);
                $demande = $demande_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$demande) {
                    throw new Exception("Demande non trouvée");
                }
                
                $user_id = SessionManager::getUserId();
                
                // ACTION : TRAITER
                if ($action == 'traiter') {
                    $update_query = "UPDATE demande_inscriptions 
                                    SET statut = 'en_traitement', 
                                        admin_traitant_id = ?,
                                        date_traitement = NOW(),
                                        commentaire_admin = ?
                                    WHERE id = ?";
                    
                    $stmt = $db->prepare($update_query);
                    $stmt->execute([$user_id, $commentaire, $demande_id]);
                    
                    $message = "Demande mise en traitement";
                    
                    $sujet = "Votre demande d'inscription ISGI est en traitement";
                    $corps = "Bonjour " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n";
                    $corps .= "Nous avons bien reçu votre demande d'inscription n°" . $demande['numero_demande'] . " et elle est actuellement en cours de traitement.\n\n";
                    $corps .= "Notre équipe examine votre dossier et vous tiendra informé(e) de l'avancement dans les plus brefs délais.\n\n";
                    $corps .= "Merci pour votre patience.\n\n";
                    $corps .= "Cordialement,\nL'équipe ISGI Congo";
                    
                    envoyerEmail($demande['email'], $sujet, $corps);
                    
                } 
                // ACTION : APPOUVER (sans paiement)
                elseif ($action == 'approuver') {
                    $update_query = "UPDATE demande_inscriptions 
                                    SET statut = 'approuvee', 
                                        validee_par = ?,
                                        date_validation = NOW(),
                                        commentaire_admin = ?
                                    WHERE id = ?";
                    
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->execute([$user_id, $commentaire, $demande_id]);
                    
                    $message = "✅ DEMANDE APPROUVÉE AVEC SUCCÈS !<br>";
                    $message .= "👤 <strong>" . $demande['nom'] . " " . $demande['prenom'] . "</strong><br>";
                    $message .= "📧 Email: " . $demande['email'] . "<br>";
                    $message .= "📋 N° demande: " . $demande['numero_demande'] . "<br>";
                    $message .= "✅ L'étudiant peut maintenant être validé pour création de compte";
                    
                    // Envoyer l'email d'approbation simple (sans paiement)
                    $sujet = "Félicitations ! Votre inscription à l'ISGI est approuvée";
                    $corps = buildApprovalEmail($demande);
                    
                    envoyerEmail($demande['email'], $sujet, $corps);
                }
                // ACTION : VALIDER (créer l'étudiant sans vérification de paiement)
                elseif ($action == 'valider') {
                    // Validation simple sans vérification de paiement
                    // Création directe de l'étudiant
                    
                    $matricule = 'ISGI-' . date('Y') . '-' . str_pad($demande_id, 5, '0', STR_PAD_LEFT);
                    
                    $site_id = $demande['site_id'] ?: 1;
                    if (!$site_id) {
                        if (strpos($demande['site_formation'], 'Brazzaville') !== false) $site_id = 1;
                        elseif (strpos($demande['site_formation'], 'Pointe-Noire') !== false) $site_id = 2;
                        elseif (strpos($demande['site_formation'], 'Ouesso') !== false) $site_id = 3;
                        else $site_id = 1;
                    }
                    
                    $check_etudiant = $db->prepare("SELECT id FROM etudiants WHERE numero_cni = ? OR matricule = ?");
                    $check_etudiant->execute([$demande['numero_cni'], $matricule]);
                    
                    if ($check_etudiant->rowCount() > 0) {
                        throw new Exception("Un étudiant avec ce CNI ou matricule existe déjà");
                    }
                    
                    // Trouver la classe (optionnel - peut être null)
                    $classe_id = null;
                    $filiere_query = "SELECT id FROM filieres WHERE nom = ? LIMIT 1";
                    $filiere_stmt = $db->prepare($filiere_query);
                    $filiere_stmt->execute([$demande['filiere']]);
                    $filiere_result = $filiere_stmt->fetch(PDO::FETCH_ASSOC);
                    $filiere_id = $filiere_result['id'] ?? null;
                    
                    if ($filiere_id) {
                        // Convertir le niveau pour correspondre au libellé
                        $niveau_libelle = '';
                        if (strpos($demande['niveau'], 'BTS 1') !== false || $demande['niveau'] == 'BTS1') {
                            $niveau_libelle = 'BTS 1ère année';
                        } elseif (strpos($demande['niveau'], 'BTS 2') !== false || $demande['niveau'] == 'BTS2') {
                            $niveau_libelle = 'BTS 2ème année';
                        } elseif (strpos($demande['niveau'], 'Licence 1') !== false || $demande['niveau'] == 'L1') {
                            $niveau_libelle = 'Licence 1';
                        } elseif (strpos($demande['niveau'], 'Licence 2') !== false || $demande['niveau'] == 'L2') {
                            $niveau_libelle = 'Licence 2';
                        } elseif (strpos($demande['niveau'], 'Licence 3') !== false || $demande['niveau'] == 'L3') {
                            $niveau_libelle = 'Licence 3';
                        } elseif (strpos($demande['niveau'], 'Master 1') !== false || $demande['niveau'] == 'M1') {
                            $niveau_libelle = 'Master 1';
                        } elseif (strpos($demande['niveau'], 'Master 2') !== false || $demande['niveau'] == 'M2') {
                            $niveau_libelle = 'Master 2';
                        } else {
                            $niveau_libelle = $demande['niveau'];
                        }
                        
                        $niveau_query = "SELECT id FROM niveaux WHERE libelle = ? LIMIT 1";
                        $niveau_stmt = $db->prepare($niveau_query);
                        $niveau_stmt->execute([$niveau_libelle]);
                        $niveau_result = $niveau_stmt->fetch(PDO::FETCH_ASSOC);
                        $niveau_id = $niveau_result['id'] ?? null;
                        
                        if (!$niveau_id) {
                            $niveau_query2 = "SELECT id FROM niveaux WHERE code = ? LIMIT 1";
                            $niveau_stmt2 = $db->prepare($niveau_query2);
                            $niveau_stmt2->execute([$demande['niveau']]);
                            $niveau_result2 = $niveau_stmt2->fetch(PDO::FETCH_ASSOC);
                            $niveau_id = $niveau_result2['id'] ?? null;
                        }
                        
                        if ($filiere_id && $niveau_id) {
                            $classe_query = "SELECT id FROM classes WHERE filiere_id = ? AND niveau_id = ? AND site_id = ? LIMIT 1";
                            $classe_stmt = $db->prepare($classe_query);
                            $classe_stmt->execute([$filiere_id, $niveau_id, $site_id]);
                            $classe_result = $classe_stmt->fetch(PDO::FETCH_ASSOC);
                            $classe_id = $classe_result['id'] ?? null;
                            
                            if (!$classe_id) {
                                $classe_query2 = "SELECT id FROM classes WHERE filiere_id = ? AND niveau_id = ? LIMIT 1";
                                $classe_stmt2 = $db->prepare($classe_query2);
                                $classe_stmt2->execute([$filiere_id, $niveau_id]);
                                $classe_result2 = $classe_stmt2->fetch(PDO::FETCH_ASSOC);
                                $classe_id = $classe_result2['id'] ?? null;
                            }
                        }
                    }
                    
                    // Créer l'étudiant sans se soucier du paiement
                    $etudiant_query = "INSERT INTO etudiants 
                                      (utilisateur_id, site_id, classe_id, matricule, nom, prenom, numero_cni, 
                                       date_naissance, lieu_naissance, sexe, nationalite, adresse, ville, pays, 
                                       profession, situation_matrimoniale,
                                       nom_pere, profession_pere, nom_mere, profession_mere,
                                       telephone_parent, nom_tuteur, profession_tuteur,
                                       telephone_tuteur, lieu_service_tuteur,
                                       photo_identite, acte_naissance, releve_notes, attestation_legalisee,
                                       filiere, niveau, cycle_formation, type_rentree, site_formation, mode_paiement,
                                       statut, date_inscription)
                                      VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW())";
                    
                    $etudiant_stmt = $db->prepare($etudiant_query);
                    $success = $etudiant_stmt->execute([
                        $site_id, 
                        $classe_id,
                        $matricule, 
                        $demande['nom'], 
                        $demande['prenom'], 
                        $demande['numero_cni'],
                        $demande['date_naissance'], 
                        $demande['lieu_naissance'], 
                        $demande['sexe'],
                        $demande['nationalite'] ?? 'Congolaise', 
                        $demande['adresse'], 
                        $demande['ville'],
                        $demande['pays'] ?? 'Congo', 
                        $demande['profession'], 
                        $demande['situation_matrimoniale'],
                        $demande['nom_pere'] ?? '', 
                        $demande['profession_pere'] ?? '',
                        $demande['nom_mere'] ?? '', 
                        $demande['profession_mere'] ?? '',
                        $demande['telephone_parent'] ?? $demande['telephone'] ?? '',
                        $demande['nom_tuteur'] ?? '',
                        $demande['profession_tuteur'] ?? '', 
                        $demande['telephone_tuteur'] ?? '',
                        $demande['lieu_service_tuteur'] ?? '', 
                        $demande['photo_identite'] ?? '',
                        $demande['acte_naissance'] ?? '', 
                        $demande['releve_notes'] ?? '',
                        $demande['attestation_legalisee'] ?? '',
                        $demande['filiere'] ?? '',           // filiere
                        $demande['niveau'] ?? '',            // niveau
                        $demande['cycle_formation'] ?? '',   // cycle_formation
                        $demande['type_rentree'] ?? '',      // type_rentree
                        $demande['site_formation'] ?? '',    // site_formation
                        $demande['mode_paiement'] ?? ''      // mode_paiement
                    ]);
                    
                    if (!$success) {
                        $error_info = $etudiant_stmt->errorInfo();
                        throw new Exception("Erreur création étudiant: " . $error_info[2]);
                    }
                    
                    $etudiant_id = $db->lastInsertId();
                    
                   
    // Mettre à jour la demande SANS la colonne etudiant_id
    $update_query = "UPDATE demande_inscriptions 
                    SET statut = 'validee', 
                        validee_par = ?,
                        date_validation = NOW(),
                        date_creation_compte = NOW(),
                        commentaire_admin = ?
                    WHERE id = ?";
    
    $update_stmt = $db->prepare($update_query);
    $update_stmt->execute([$user_id, $commentaire, $demande_id]);
    
                    
                    $message = "🎉 ÉTUDIANT CRÉÉ AVEC SUCCÈS !<br>";
                    $message .= "📋 <strong>Matricule: $matricule</strong><br>";
                    $message .= "👤 <strong>" . $demande['nom'] . " " . $demande['prenom'] . "</strong><br>";
                    $message .= "📧 Email: " . $demande['email'] . "<br>";
                    $message .= "🎓 Filière: " . $demande['filiere'] . " (" . $demande['niveau'] . ")<br>";
                    $message .= "🆔 ID étudiant: <strong>$etudiant_id</strong>";
                    
                    // Envoyer l'email de bienvenue
                    $sujet = "Bienvenue à l'ISGI ! Votre compte étudiant est créé";
                    $corps = buildValidationEmail($demande, $matricule);
                    
                    envoyerEmail($demande['email'], $sujet, $corps);
                    
                } 
                // ACTION : REJETER
                elseif ($action == 'rejeter') {
                    $update_query = "UPDATE demande_inscriptions 
                                    SET statut = 'rejetee', 
                                        admin_traitant_id = ?,
                                        date_traitement = NOW(),
                                        raison_rejet = ?,
                                        commentaire_admin = ?
                                    WHERE id = ?";
                    
                    $stmt = $db->prepare($update_query);
                    $stmt->execute([$user_id, $raison_rejet, $commentaire, $demande_id]);
                    
                    $message = "Demande rejetée";
                    
                    $sujet = "Votre demande d'inscription ISGI";
                    $corps = "Bonjour " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n";
                    $corps .= "Nous avons examiné votre demande d'inscription n°" . $demande['numero_demande'] . ".\n\n";
                    $corps .= "Malheureusement, votre demande n'a pas pu être acceptée pour le moment.\n\n";
                    $corps .= "📋 <strong>Raison du rejet:</strong>\n";
                    $corps .= $raison_rejet . "\n\n";
                    $corps .= "🔄 <strong>Que faire ensuite ?</strong>\n";
                    $corps .= "• Vous pouvez soumettre une nouvelle demande avec des informations complètes et correctes\n";
                    $corps .= "• Si vous avez des questions, contactez-nous à support@isgi.cg\n\n";
                    $corps .= "Nous vous remercions pour votre intérêt pour l'ISGI.\n\n";
                    $corps .= "Cordialement,\nL'équipe ISGI Congo";
                    
                    envoyerEmail($demande['email'], $sujet, $corps);
                }
                
                $db->commit();
                $message_type = 'success';
                
                // Redirection pour éviter la soumission multiple
                header("Location: demandes.php?message=" . urlencode($message) . "&type=" . $message_type . "&statut=" . urlencode($statut_filter));
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $message = "Erreur: " . $e->getMessage();
                $message_type = 'danger';
                error_log("Erreur traitement: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_GET['message'])) {
        $message = $_GET['message'];
        $message_type = $_GET['type'] ?? 'info';
    }
    
} catch (Exception $e) {
    $error = "Erreur lors de la récupération des données: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --accent-color: #e74c3c;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #f8f9fa;
        --card-bg: #ffffff;
        --text-color: #212529;
        --text-muted: #6c757d;
        --sidebar-bg: #2c3e50;
        --sidebar-text: #ffffff;
        --border-color: #dee2e6;
    }
    
    [data-theme="dark"] {
        --primary-color: #3498db;
        --secondary-color: #2980b9;
        --accent-color: #e74c3c;
        --success-color: #2ecc71;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #121212;
        --card-bg: #1e1e1e;
        --text-color: #e0e0e0;
        --text-muted: #a0a0a0;
        --sidebar-bg: #1a1a1a;
        --sidebar-text: #ffffff;
        --border-color: #333333;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-color);
        margin: 0;
        padding: 0;
        min-height: 100vh;
    }
    
    .app-container {
        display: flex;
        min-height: 100vh;
    }
    
    /* Sidebar */
    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-text);
        position: fixed;
        height: 100vh;
        overflow-y: auto;
        z-index: 1000;
    }
    
    .sidebar-header {
        padding: 20px 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
    }
    
    .sidebar-logo {
        width: 50px;
        height: 50px;
        background: var(--secondary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 10px;
    }
    
    .user-info {
        text-align: center;
        margin-bottom: 20px;
        padding: 0 15px;
    }
    
    .user-role {
        display: inline-block;
        padding: 4px 12px;
        background: var(--secondary-color);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        margin-top: 5px;
    }
    
    /* Navigation */
    .sidebar-nav {
        padding: 15px;
    }
    
    .nav-section {
        margin-bottom: 25px;
    }
    
    .nav-section-title {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255, 255, 255, 0.6);
        margin-bottom: 10px;
        padding: 0 10px;
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        color: var(--sidebar-text);
        text-decoration: none;
        border-radius: 5px;
        margin-bottom: 5px;
        transition: all 0.3s;
    }
    
    .nav-link:hover, .nav-link.active {
        background-color: var(--secondary-color);
        color: white;
    }
    
    .nav-link i {
        width: 20px;
        margin-right: 10px;
        text-align: center;
    }
    
    .nav-badge {
        margin-left: auto;
        background: var(--accent-color);
        color: white;
        font-size: 11px;
        padding: 2px 6px;
        border-radius: 10px;
    }
    
    /* Contenu principal */
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
    }
    
    /* Cartes */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    
    .card:hover {
        transform: translateY(-2px);
    }
    
    .card-header {
        background-color: rgba(0, 0, 0, 0.03);
        border-bottom: 1px solid var(--border-color);
        padding: 15px 20px;
    }
    
    .card-body {
        padding: 20px;
    }
    
    /* Stat cards */
    .stat-card {
        text-align: center;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        color: white;
    }
    
    .stat-value {
        font-size: 1.8rem;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    /* Détails */
    .detail-row {
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .detail-label {
        font-weight: 600;
        color: var(--primary-color);
    }
    
    .document-card {
        border: 1px solid var(--border-color);
        border-radius: 5px;
        margin-bottom: 10px;
    }
    
    /* Tableaux */
    .table {
        color: var(--text-color);
    }
    
    .table thead th {
        background-color: var(--primary-color);
        color: white;
        border: none;
        padding: 15px;
    }
    
    .table tbody td {
        border-color: var(--border-color);
        padding: 15px;
        color: var(--text-color);
    }
    
    .table tbody tr:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .table tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Badges */
    .badge {
        font-size: 0.75em;
        padding: 4px 8px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            width: 70px;
            overflow-x: hidden;
        }
        
        .sidebar-header, .user-info, .nav-section-title, .nav-link span {
            display: none;
        }
        
        .nav-link {
            justify-content: center;
            padding: 15px;
        }
        
        .nav-link i {
            margin-right: 0;
            font-size: 18px;
        }
        
        .main-content {
            margin-left: 70px;
            padding: 15px;
        }
        
        .stat-value {
            font-size: 1.5rem;
        }
    }
    
    /* En-têtes */
    h1, h2, h3, h4, h5, h6 {
        color: var(--text-color);
    }
    
    .content-header h2 {
        color: var(--text-color);
    }
    
    .content-header .text-muted {
        color: var(--text-muted);
    }
    
    /* Boutons */
    .btn-outline-light {
        color: var(--sidebar-text);
        border-color: var(--sidebar-text);
    }
    
    .btn-outline-light:hover {
        background-color: var(--sidebar-text);
        color: var(--sidebar-bg);
    }
    
    /* Formulaires */
    .form-control, .form-select {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .form-control:focus, .form-select:focus {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
    }
    
    /* Progress bars */
    .progress {
        background-color: var(--border-color);
    }
    
    .progress-bar {
        background-color: var(--primary-color);
    }
    
    /* List group */
    .list-group-item {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .list-group-item:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }
    
    [data-theme="dark"] .list-group-item:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Pagination */
    .pagination .page-item .page-link {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--border-color);
    }
    
    .pagination .page-item.active .page-link {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
        color: white;
    }
    
    /* Modal styles */
    .modal-content {
        background-color: var(--card-bg);
        color: var(--text-color);
    }
    
    .modal-header {
        border-bottom-color: var(--border-color);
    }
    
    .modal-footer {
        border-top-color: var(--border-color);
    }
    
    /* Alertes */
    .alert {
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        background-color: var(--card-bg);
    }
    
    .alert-info {
        background-color: rgba(23, 162, 184, 0.1);
        border-left: 4px solid var(--info-color);
    }
    
    .alert-success {
        background-color: rgba(39, 174, 96, 0.1);
        border-left: 4px solid var(--success-color);
    }
    
    .alert-warning {
        background-color: rgba(243, 156, 18, 0.1);
        border-left: 4px solid var(--warning-color);
    }
    
    .alert-danger {
        background-color: rgba(231, 76, 60, 0.1);
        border-left: 4px solid var(--accent-color);
    }
    
    /* Action buttons */
    .action-buttons .btn-group {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }
    
    .action-buttons .btn-sm {
        padding: 4px 8px;
        font-size: 12px;
    }
    
    /* Fix pour éviter le tremblement de la page */
    .action-form {
        margin: 0;
    }
    
    /* Animation pour le chargement */
    .spinner-container {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 200px;
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI ADMIN</h5>
                <div class="user-role">Administrateur Principal</div>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrateur'); ?></p>
                <small>Vue Globale Multi-Sites</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard Global</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Multi-Sites</div>
                    <a href="sites.php" class="nav-link">
                        <i class="fas fa-building"></i>
                        <span>Tous les Sites</span>
                    </a>
                    <a href="utilisateurs.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Tous les Utilisateurs</span>
                    </a>
                    <a href="validation_comptes.php" class="nav-link">
                        <i class="fas fa-user-check"></i>
                        <span>Validation des Comptes</span>
                        <?php 
                        // Récupérer le nombre de comptes en attente pour le badge
                        if (class_exists('Database')) {
                            try {
                                $db_count = Database::getInstance()->getConnection();
                                $count_result = $db_count->query("SELECT COUNT(*) as count FROM utilisateurs WHERE statut = 'en_attente'")->fetch();
                                $count_validation = $count_result['count'] ?? 0;
                                if ($count_validation > 0): ?>
                                <span class="nav-badge"><?php echo $count_validation; ?></span>
                                <?php endif;
                            } catch (Exception $e) {
                                // Silencieux
                            }
                        }
                        ?>
                    </a>
                    <a href="demandes.php" class="nav-link active">
                        <i class="fas fa-user-plus"></i>
                        <span>Demandes d'Inscription</span>
                        <?php if ($stats['en_attente'] > 0): ?>
                        <span class="nav-badge"><?php echo $stats['en_attente']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Académique Global</div>
                    <a href="etudiants.php" class="nav-link">
                        <i class="fas fa-user-graduate"></i>
                        <span>Tous les Étudiants</span>
                    </a>
                    <a href="professeurs.php" class="nav-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Tous les Professeurs</span>
                    </a>
                    <a href="calendrier_examens.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Calendrier Examens</span>
                    </a>
                    <a href="calendrier_academique.php" class="nav-link">
                        <i class="fas fa-calendar"></i>
                        <span>Calendrier Académique</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Pédagogie & Ressources</div>
                    <a href="cours_en_ligne.php" class="nav-link">
                        <i class="fas fa-laptop"></i>
                        <span>Cours en Ligne</span>
                    </a>
                    <a href="bibliotheque/bibliotheque.php" class="nav-link">
                        <i class="fas fa-book"></i>
                        <span>Bibliothèque</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Finances Globales</div>
                    <a href="paiements.php" class="nav-link">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Gestion Paiements</span>
                    </a>
                    <a href="dettes.php" class="nav-link">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Gestion Dettes</span>
                    </a>
                    <a href="rapport_financier.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        <span>Rapports Financiers</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Administration</div>
                    <a href="reunions.php" class="nav-link">
                        <i class="fas fa-users"></i>
                        <span>Réunions</span>
                    </a>
                    <a href="rapports_statistiques.php" class="nav-link">
                        <i class="fas fa-chart-pie"></i>
                        <span>Rapports Statistiques</span>
                    </a>
                    <a href="notifications.php" class="nav-link">
                        <i class="fas fa-bell"></i>
                        <span>Notifications</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="../../auth/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Contenu Principal -->
        <div class="main-content">
            <div class="content-header mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0"><i class="fas fa-user-plus me-2"></i>Gestion des Demandes d'Inscription</h2>
                        <p class="text-muted mb-0">Validez ou rejetez les demandes d'inscription</p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Actualiser</button>
                        <a href="demandes.php?statut=en_attente" class="btn btn-warning"><i class="fas fa-clock"></i> En attente (<?php echo $stats['en_attente']; ?>)</a>
                        <a href="demandes.php?statut=en_traitement" class="btn btn-info"><i class="fas fa-cogs"></i> En traitement (<?php echo $stats['en_traitement']; ?>)</a>
                    </div>
                </div>
            </div>
            
            <?php if(isset($error)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'info-circle'; ?>"></i>
                <?php echo nl2br(htmlspecialchars($message)); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <div class="card mb-4">
                <div class="card-body">
                    <div class="filter-tabs">
                        <div class="btn-group" role="group">
                            <a href="demandes.php" class="btn <?php echo empty($statut_filter) || $statut_filter == 'tous' ? 'btn-primary' : 'btn-outline-primary'; ?>">Toutes (<?php echo $stats['total']; ?>)</a>
                            <a href="demandes.php?statut=en_attente" class="btn <?php echo $statut_filter == 'en_attente' ? 'btn-warning' : 'btn-outline-warning'; ?>">En attente (<?php echo $stats['en_attente']; ?>)</a>
                            <a href="demandes.php?statut=en_traitement" class="btn <?php echo $statut_filter == 'en_traitement' ? 'btn-info' : 'btn-outline-info'; ?>">En traitement (<?php echo $stats['en_traitement']; ?>)</a>
                            <a href="demandes.php?statut=approuvee" class="btn <?php echo $statut_filter == 'approuvee' ? 'btn-primary' : 'btn-outline-primary'; ?>">Approuvées (<?php echo $stats['approuvee']; ?>)</a>
                            <a href="demandes.php?statut=validee" class="btn <?php echo $statut_filter == 'validee' ? 'btn-success' : 'btn-outline-success'; ?>">Validées (<?php echo $stats['validee']; ?>)</a>
                            <a href="demandes.php?statut=rejetee" class="btn <?php echo $statut_filter == 'rejetee' ? 'btn-danger' : 'btn-outline-danger'; ?>">Rejetées (<?php echo $stats['rejetee']; ?>)</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #f1c40f);">
                        <div class="stat-value"><?php echo $stats['en_attente']; ?></div>
                        <div class="stat-label">En attente</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #3498db, #2980b9);">
                        <div class="stat-value"><?php echo $stats['en_traitement']; ?></div>
                        <div class="stat-label">En traitement</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #2c3e50, #3498db);">
                        <div class="stat-value"><?php echo $stats['approuvee']; ?></div>
                        <div class="stat-label">Approuvées</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #27ae60, #2ecc71);">
                        <div class="stat-value"><?php echo $stats['validee']; ?></div>
                        <div class="stat-label">Validées</div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtres de recherche</h5></div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3" id="filterForm">
                        <div class="col-md-3">
                            <label class="form-label">Statut</label>
                            <select name="statut" class="form-select">
                                <option value="tous" <?php echo $statut_filter == 'tous' ? 'selected' : ''; ?>>Tous les statuts</option>
                                <option value="en_attente" <?php echo $statut_filter == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                <option value="en_traitement" <?php echo $statut_filter == 'en_traitement' ? 'selected' : ''; ?>>En traitement</option>
                                <option value="approuvee" <?php echo $statut_filter == 'approuvee' ? 'selected' : ''; ?>>Approuvées</option>
                                <option value="validee" <?php echo $statut_filter == 'validee' ? 'selected' : ''; ?>>Validées</option>
                                <option value="rejetee" <?php echo $statut_filter == 'rejetee' ? 'selected' : ''; ?>>Rejetées</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Site</label>
                            <select name="site" class="form-select">
                                <option value="">Tous les sites</option>
                                <?php foreach($sites as $site): ?>
                                <option value="<?php echo $site['id']; ?>" <?php echo $site_filter == $site['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date début</label>
                            <input type="date" name="date_debut" class="form-control" value="<?php echo htmlspecialchars($date_debut); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date fin</label>
                            <input type="date" name="date_fin" class="form-control" value="<?php echo htmlspecialchars($date_fin); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Recherche</label>
                            <input type="text" name="search" class="form-control" placeholder="Nom, email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-12">
                            <div class="d-flex justify-content-between mt-3">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrer</button>
                                <a href="demandes.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i>Réinitialiser</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-list me-2"></i>Liste des Demandes (<?php echo count($demandes); ?>)</h5></div>
                <div class="card-body">
                    <?php if(empty($demandes)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>Aucune demande trouvée</h5>
                        <p class="mb-0">Aucune demande ne correspond aux critères de recherche.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Demandeur</th>
                                    <th>Email</th>
                                    <th>Filière</th>
                                    <th>Site</th>
                                    <th>Date demande</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($demandes as $demande): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($demande['numero_demande']); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($demande['telephone']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($demande['email']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($demande['filiere']); ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($demande['niveau']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($demande['site_nom'] ?? 'Non assigné'); ?></td>
                                    <td>
                                        <?php echo formatDateFr($demande['date_demande'], 'd/m/Y H:i'); ?>
                                        <br><small class="text-muted">
                                            <?php 
                                            $date1 = new DateTime($demande['date_demande']);
                                            $date2 = new DateTime();
                                            $interval = $date1->diff($date2);
                                            echo $interval->days . ' jour(s)';
                                            ?>
                                        </small>
                                    </td>
                                    <td><?php echo getStatutBadge($demande['statut']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-sm btn-info view-details" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#viewModal"
                                                    data-demande-id="<?php echo $demande['id']; ?>"
                                                    data-demande-nom="<?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="telechargerDocuments(<?php echo $demande['id']; ?>, '<?php echo addslashes($demande['nom'] . ' ' . $demande['prenom']); ?>')">
                                                <i class="fas fa-download"></i>
                                            </button>
                                            <?php if($demande['statut'] == 'en_attente' || $demande['statut'] == 'en_traitement'): ?>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-primary process-approve" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#approveModal"
                                                        data-demande-id="<?php echo $demande['id']; ?>"
                                                        data-demande-nom="<?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?>"
                                                        data-mode-paiement="<?php echo htmlspecialchars($demande['mode_paiement']); ?>">
                                                    <i class="fas fa-check-circle"></i> Approuver
                                                </button>
                                                <?php if($demande['statut'] == 'en_attente'): ?>
                                                <button type="button" class="btn btn-sm btn-warning process-treat" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#processModal"
                                                        data-demande-id="<?php echo $demande['id']; ?>"
                                                        data-demande-nom="<?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?>">
                                                    <i class="fas fa-cogs"></i> Traiter
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-danger process-reject" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#rejectModal"
                                                        data-demande-id="<?php echo $demande['id']; ?>"
                                                        data-demande-nom="<?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?>">
                                                    <i class="fas fa-times"></i> Rejeter
                                                </button>
                                            </div>
                                            <?php elseif($demande['statut'] == 'approuvee'): ?>
                                            <button type="button" class="btn btn-sm btn-success process-validate" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#validateModal"
                                                    data-demande-id="<?php echo $demande['id']; ?>"
                                                    data-demande-nom="<?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?>"
                                                    data-mode-paiement="<?php echo htmlspecialchars($demande['mode_paiement']); ?>">
                                                <i class="fas fa-user-check"></i> Valider étudiant
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <nav aria-label="Pagination">
                        <ul class="pagination justify-content-center">
                            <li class="page-item disabled">
                                <a class="page-link" href="#">Précédent</a>
                            </li>
                            <li class="page-item active">
                                <a class="page-link" href="#">1</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">2</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">3</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="#">Suivant</a>
                            </li>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Vue détaillée (UN SEUL MODAL POUR TOUS) -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails de la demande</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewModalBody">
                    <div class="spinner-container">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Chargement...</span>
                        </div>
                        <p class="ms-3">Chargement des détails...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Traiter (UN SEUL MODAL POUR TOUS) -->
    <div class="modal fade" id="processModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="demandes.php" class="action-form">
                    <div class="modal-header">
                        <h5 class="modal-title">Traiter la demande</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Êtes-vous sûr de vouloir traiter la demande de <strong id="processDemandeNom"></strong> ?</p>
                        <input type="hidden" id="processDemandeId" name="demande_id" value="">
                        <input type="hidden" name="action" value="traiter">
                        <div class="mb-3">
                            <label class="form-label">Commentaire (optionnel)</label>
                            <textarea name="commentaire" class="form-control" rows="3" placeholder="Ajoutez un commentaire..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-warning"><i class="fas fa-cogs me-2"></i>Mettre en traitement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Approuver (UN SEUL MODAL POUR TOUS) -->
    <div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="demandes.php" class="action-form">
                    <div class="modal-header">
                        <h5 class="modal-title">Approuver la demande</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Cette action approuvera la demande. L'étudiant recevra un email de confirmation.
                        </div>
                        <p>Approuver la demande de <strong id="approveDemandeNom"></strong> ?</p>
                        <input type="hidden" id="approveDemandeId" name="demande_id" value="">
                        <input type="hidden" name="action" value="approuver">
                        <div class="mb-3">
                            <label class="form-label">Commentaire (optionnel)</label>
                            <textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire d'approbation..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check-circle me-2"></i>Approuver et envoyer email</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Valider étudiant (UN SEUL MODAL POUR TOUS) -->
    <div class="modal fade" id="validateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="demandes.php" class="action-form">
                    <div class="modal-header">
                        <h5 class="modal-title">Valider et créer l'étudiant</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Cette action créera un étudiant dans la base de données.
                        </div>
                        <p>Valider la demande et créer l'étudiant <strong id="validateDemandeNom"></strong> ?</p>
                        <input type="hidden" id="validateDemandeId" name="demande_id" value="">
                        <input type="hidden" name="action" value="valider">
                        <div class="mb-3">
                            <label class="form-label">Commentaire (optionnel)</label>
                            <textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire de validation..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-user-check me-2"></i>Valider et créer étudiant</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Rejeter (UN SEUL MODAL POUR TOUS) -->
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="demandes.php" class="action-form">
                    <div class="modal-header">
                        <h5 class="modal-title">Rejeter la demande</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Rejeter la demande de <strong id="rejectDemandeNom"></strong> ?</p>
                        <input type="hidden" id="rejectDemandeId" name="demande_id" value="">
                        <input type="hidden" name="action" value="rejeter">
                        <div class="mb-3">
                            <label class="form-label">Raison du rejet <span class="text-danger">*</span></label>
                            <textarea name="raison_rejet" class="form-control" rows="3" placeholder="Expliquez la raison du rejet..." required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Commentaire (optionnel)</label>
                            <textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire additionnel..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-times me-2"></i>Rejeter</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        // Mettre à jour l'attribut
        html.setAttribute('data-theme', newTheme);
        
        // Sauvegarder dans un cookie (30 jours)
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        // Mettre à jour le bouton
        const button = event.target.closest('button');
        if (button) {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Fonction pour télécharger les documents
    function telechargerDocuments(demandeId, demandeNom) {
        if (confirm('Télécharger tous les documents de cette demande ?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'telecharger_documents.php';
            form.style.display = 'none';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'demande_id';
            input.value = demandeId;
            form.appendChild(input);
            
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
    }
    
    // Données des demandes pour la vue détaillée
    const demandesData = <?php 
        $demandesData = [];
        foreach($demandes as $demande) {
            $demandesData[$demande['id']] = [
                'nom' => $demande['nom'],
                'prenom' => $demande['prenom'],
                'email' => $demande['email'],
                'telephone' => $demande['telephone'],
                'filiere' => $demande['filiere'],
                'niveau' => $demande['niveau'],
                'site_formation' => $demande['site_formation'],
                'mode_paiement' => $demande['mode_paiement'],
                'statut' => $demande['statut'],
                'numero_demande' => $demande['numero_demande'],
                'date_naissance' => $demande['date_naissance'],
                'lieu_naissance' => $demande['lieu_naissance'],
                'sexe' => $demande['sexe'],
                'numero_cni' => $demande['numero_cni'],
                'adresse' => $demande['adresse'],
                'ville' => $demande['ville'],
                'pays' => $demande['pays'],
                'profession' => $demande['profession'],
                'cycle_formation' => $demande['cycle_formation'],
                'domaine' => $demande['domaine'],
                'type_rentree' => $demande['type_rentree'],
                'date_demande' => $demande['date_demande'],
                'date_traitement' => $demande['date_traitement'],
                'date_validation' => $demande['date_validation'],
                'commentaire_admin' => $demande['commentaire_admin'],
                'admin_traitant_nom' => $demande['admin_traitant_nom'] ?? '',
                'validateur_nom' => $demande['validateur_nom'] ?? ''
            ];
        }
        echo json_encode($demandesData);
    ?>;
    
    // Gestionnaire pour le modal de visualisation
    document.addEventListener('DOMContentLoaded', function() {
        // Récupérer le thème sauvegardé ou utiliser 'light' par défaut
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        // Mettre à jour le bouton du thème
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
        
        // Fermer automatiquement les alertes après 5 secondes
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Empêcher la soumission multiple des formulaires d'action
        document.querySelectorAll('.action-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                const submitButton = this.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Traitement en cours...';
                }
            });
        });
        
        // Gestion du modal de visualisation
        const viewModal = document.getElementById('viewModal');
        if (viewModal) {
            viewModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const demandeId = button.getAttribute('data-demande-id');
                
                if (demandeId && demandesData[demandeId]) {
                    const demande = demandesData[demandeId];
                    const modalBody = document.getElementById('viewModalBody');
                    
                    // Formater la date
                    function formatDate(dateStr) {
                        if (!dateStr || dateStr === '0000-00-00') return '';
                        const date = new Date(dateStr);
                        return date.toLocaleDateString('fr-FR', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    }
                    
                    // Mettre à jour le contenu du modal
                    modalBody.innerHTML = `
                        <div class="demande-details">
                            <h6 class="mb-3">Informations personnelles</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="detail-row">
                                        <div class="detail-label">Nom complet</div>
                                        <div>${demande.nom} ${demande.prenom}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Date de naissance</div>
                                        <div>${formatDate(demande.date_naissance)} à ${demande.lieu_naissance}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Sexe</div>
                                        <div>${demande.sexe}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">CNI</div>
                                        <div>${demande.numero_cni}</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="detail-row">
                                        <div class="detail-label">Adresse</div>
                                        <div>${demande.adresse}, ${demande.ville}, ${demande.pays}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Téléphone</div>
                                        <div>${demande.telephone}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Email</div>
                                        <div>${demande.email}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Profession</div>
                                        <div>${demande.profession}</div>
                                    </div>
                                </div>
                            </div>
                            
                            <h6 class="mb-3 mt-4">Informations académiques</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="detail-row">
                                        <div class="detail-label">Cycle</div>
                                        <div>${demande.cycle_formation}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Domaine</div>
                                        <div>${demande.domaine}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Filière</div>
                                        <div>${demande.filiere}</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="detail-row">
                                        <div class="detail-label">Niveau</div>
                                        <div>${demande.niveau}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Type rentrée</div>
                                        <div>${demande.type_rentree}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Site formation</div>
                                        <div>${demande.site_formation}</div>
                                    </div>
                                </div>
                            </div>
                            
                            <h6 class="mb-3 mt-4">Informations administratives</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="detail-row">
                                        <div class="detail-label">Numéro demande</div>
                                        <div>${demande.numero_demande}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Date demande</div>
                                        <div>${formatDate(demande.date_demande)}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Statut</div>
                                        <div>${demande.statut}</div>
                                    </div>
                                    <div class="detail-row">
                                        <div class="detail-label">Mode de paiement</div>
                                        <div>${demande.mode_paiement}</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    ${demande.date_traitement ? `
                                    <div class="detail-row">
                                        <div class="detail-label">Date traitement</div>
                                        <div>${formatDate(demande.date_traitement)}</div>
                                    </div>
                                    ` : ''}
                                    ${demande.date_validation ? `
                                    <div class="detail-row">
                                        <div class="detail-label">Date validation</div>
                                        <div>${formatDate(demande.date_validation)}</div>
                                    </div>
                                    ` : ''}
                                    ${demande.commentaire_admin ? `
                                    <div class="detail-row">
                                        <div class="detail-label">Commentaire admin</div>
                                        <div>${demande.commentaire_admin}</div>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                            
                            ${(demande.admin_traitant_nom || demande.validateur_nom) ? `
                            <h6 class="mb-3 mt-4">Traçabilité</h6>
                            <div class="row">
                                ${demande.admin_traitant_nom ? `
                                <div class="col-md-6">
                                    <div class="detail-row">
                                        <div class="detail-label">Traité par</div>
                                        <div>${demande.admin_traitant_nom}</div>
                                        ${demande.date_traitement ? `
                                        <small class="text-muted">${formatDate(demande.date_traitement)}</small>
                                        ` : ''}
                                    </div>
                                </div>
                                ` : ''}
                                ${demande.validateur_nom ? `
                                <div class="col-md-6">
                                    <div class="detail-row">
                                        <div class="detail-label">Validé par</div>
                                        <div>${demande.validateur_nom}</div>
                                        ${demande.date_validation ? `
                                        <small class="text-muted">${formatDate(demande.date_validation)}</small>
                                        ` : ''}
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                            ` : ''}
                            
                            <div class="alert alert-info mt-4">
                                <i class="fas fa-info-circle"></i> Pour visualiser les documents téléchargés, utilisez le bouton de téléchargement.
                            </div>
                        </div>
                    `;
                }
            });
        }
        
        // Gestion du modal de traitement
        const processModal = document.getElementById('processModal');
        if (processModal) {
            processModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const demandeId = button.getAttribute('data-demande-id');
                const demandeNom = button.getAttribute('data-demande-nom');
                
                document.getElementById('processDemandeId').value = demandeId;
                document.getElementById('processDemandeNom').textContent = demandeNom;
            });
        }
        
        // Gestion du modal d'approbation
        const approveModal = document.getElementById('approveModal');
        if (approveModal) {
            approveModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const demandeId = button.getAttribute('data-demande-id');
                const demandeNom = button.getAttribute('data-demande-nom');
                const modePaiement = button.getAttribute('data-mode-paiement');
                
                document.getElementById('approveDemandeId').value = demandeId;
                document.getElementById('approveDemandeNom').textContent = demandeNom;
            });
        }
        
        // Gestion du modal de validation
        const validateModal = document.getElementById('validateModal');
        if (validateModal) {
            validateModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const demandeId = button.getAttribute('data-demande-id');
                const demandeNom = button.getAttribute('data-demande-nom');
                const modePaiement = button.getAttribute('data-mode-paiement');
                
                document.getElementById('validateDemandeId').value = demandeId;
                document.getElementById('validateDemandeNom').textContent = demandeNom;
            });
        }
        
        // Gestion du modal de rejet
        const rejectModal = document.getElementById('rejectModal');
        if (rejectModal) {
            rejectModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const demandeId = button.getAttribute('data-demande-id');
                const demandeNom = button.getAttribute('data-demande-nom');
                
                document.getElementById('rejectDemandeId').value = demandeId;
                document.getElementById('rejectDemandeNom').textContent = demandeNom;
            });
        }
    });
    </script>
</body>
</html>