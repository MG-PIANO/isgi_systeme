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

/**
 * Générer un lien de paiement unique avec interface MTN Mobile Money
 */
function genererLienPaiement($demande_id, $numero_demande, $demande_data) {
    global $db;
    
    // Générer un token sécurisé
    $token = bin2hex(random_bytes(32));
    
    // Calculer les frais en fonction du mode de paiement
    $frais_pourcentage = ($demande_data['mode_paiement'] == 'MTN Mobile Money' || 
                         $demande_data['mode_paiement'] == 'Airtel Money') ? 1.5 : 0;
    
    // Déterminer le montant des frais (à adapter selon votre logique)
    $montant_frais = 0;
    if (in_array($demande_data['cycle_formation'], ['Licence', 'Master'])) {
        $montant_frais = 25000; // Exemple pour licence/master
    } else {
        $montant_frais = 25000; // Exemple pour BTS
    }
    
    $frais_transaction = $montant_frais * ($frais_pourcentage / 100);
    $total_amount = $montant_frais + $frais_transaction;
    
    // Enregistrer le token avec plus d'informations
    $query = "UPDATE demande_inscriptions SET 
              token_paiement = ?, 
              date_expiration_token = DATE_ADD(NOW(), INTERVAL 7 DAY)
              WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$token, $demande_id]);
    
    // Créer un enregistrement de paiement
    $payment_query = "INSERT INTO paiements (
        etudiant_id,
        type_frais_id,
        annee_academique_id,
        reference,
        montant,
        frais_transaction,
        mode_paiement,
        numero_telephone,
        operateur_mobile,
        date_paiement,
        statut,
        date_creation
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'en_attente', NOW())";
    
    // Déterminer l'opérateur
    $operateur = 'MTN';
    if ($demande_data['mode_paiement'] == 'Airtel Money') {
        $operateur = 'Airtel';
    } elseif ($demande_data['mode_paiement'] == 'Espèce') {
        $operateur = null;
    }
    
    $payment_stmt = $db->prepare($payment_query);
    $payment_stmt->execute([
        null, // etudiant_id (null car pas encore créé)
        1,    // type_frais_id (à adapter)
        1,    // annee_academique_id (à adapter)
        'PAY-' . date('Ymd') . '-' . str_pad($demande_id, 5, '0', STR_PAD_LEFT),
        $montant_frais,
        $frais_transaction,
        $demande_data['mode_paiement'],
        $demande_data['telephone'],
        $operateur
    ]);
    
    // Récupérer le site de formation
    $site_id = $demande_data['site_id'] ?? 1;
    if (!$site_id) {
        if (strpos($demande_data['site_formation'], 'Brazzaville') !== false) $site_id = 1;
        elseif (strpos($demande_data['site_formation'], 'Pointe-Noire') !== false) $site_id = 2;
        elseif (strpos($demande_data['site_formation'], 'Ouesso') !== false) $site_id = 3;
        else $site_id = 1;
    }
    
    // Générer le lien vers l'interface de paiement
    $base_url = "http://" . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['PHP_SELF']));
    
    return [
        'url' => $base_url . "/payment-interface/?token=" . $token . 
                "&demande_id=" . $demande_id . 
                "&fee_type_id=1" .  // À adapter selon vos types de frais
                "&reference=INSCRIPTION-" . $numero_demande,
        'token' => $token,
        'montant_frais' => $montant_frais,
        'frais_transaction' => $frais_transaction,
        'total_amount' => $total_amount
    ];
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
                    $corps .= "Merci pour votre patience.\n\nCordialement,\nL'équipe ISGI Congo";
                    
                    envoyerEmail($demande['email'], $sujet, $corps);
                    
                } 
               // ACTION : APPOUVER
elseif ($action == 'approuver') {
    $update_query = "UPDATE demande_inscriptions 
                    SET statut = 'approuvee', 
                        validee_par = ?,
                        date_validation = NOW(),
                        commentaire_admin = ?
                    WHERE id = ?";
    
    $update_stmt = $db->prepare($update_query);
    $update_stmt->execute([$user_id, $commentaire, $demande_id]);
    
    // Générer le lien de paiement avec interface mobile money
    $paiement_info = genererLienPaiement($demande_id, $demande['numero_demande'], $demande);
    $lien_paiement = $paiement_info['url'];
    
    $message = "✅ DEMANDE APPROUVÉE AVEC SUCCÈS !<br>";
    $message .= "👤 <strong>" . $demande['nom'] . " " . $demande['prenom'] . "</strong><br>";
    $message .= "📧 Email: " . $demande['email'] . "<br>";
    $message .= "📋 N° demande: " . $demande['numero_demande'] . "<br>";
    $message .= "💰 Montant frais: " . number_format($paiement_info['montant_frais'], 0, ',', ' ') . " FCFA<br>";
    $message .= "💳 Frais transaction: " . number_format($paiement_info['frais_transaction'], 0, ',', ' ') . " FCFA<br>";
    $message .= "💰 Total à payer: " . number_format($paiement_info['total_amount'], 0, ',', ' ') . " FCFA<br>";
    $message .= "🔗 Lien de paiement généré";
    
    // Envoyer l'email avec les détails du paiement
    $sujet = "Félicitations ! Votre inscription à l'ISGI est approuvée - Procédez au paiement";
    
    /**
 * Construire l'email d'approbation avec détails du paiement
 */
function buildApprovalEmail($demande, $paiement_info, $lien_paiement) {
    $montant_format = number_format($paiement_info['montant_frais'], 0, ',', ' ');
    $frais_format = number_format($paiement_info['frais_transaction'], 0, ',', ' ');
    $total_format = number_format($paiement_info['total_amount'], 0, ',', ' ');
    
    $paiement_online = in_array($demande['mode_paiement'], ['MTN Mobile Money', 'Airtel Money']);
    
    if ($paiement_online) {
        return "Félicitations " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n" .
               "Votre demande d'inscription à l'ISGI a été approuvée avec succès !\n\n" .
               "📋 <strong>Numéro de dossier: " . $demande['numero_demande'] . "</strong>\n\n" .
               "✅ <strong>Votre inscription est validée</strong>\n\n" .
               "💰 <strong>Détails des frais:</strong>\n" .
               "• Frais d'inscription: " . $montant_format . " FCFA\n" .
               "• Frais de transaction: " . $frais_format . " FCFA\n" .
               "• <strong>TOTAL À PAYER: " . $total_format . " FCFA</strong>\n\n" .
               "📱 <strong>Mode de paiement choisi:</strong> " . $demande['mode_paiement'] . "\n\n" .
               "🔗 <strong>Lien de paiement sécurisé:</strong>\n" .
               $lien_paiement . "\n\n" .
               "⏰ <strong>Durée de validité du lien:</strong> 7 jours\n\n" .
               "📝 <strong>Instructions pour le paiement:</strong>\n" .
               "1. Cliquez sur le lien ci-dessus\n" .
               "2. Entrez votre numéro de téléphone " . $demande['mode_paiement'] . "\n" .
               "3. Confirmez le paiement sur votre téléphone\n" .
               "4. Vous recevrez une confirmation par SMS et email\n\n" .
               "⚠️ <strong>Important:</strong>\n" .
               "• Assurez-vous d'avoir suffisamment de crédit sur votre compte\n" .
               "• Le lien est personnel et ne doit pas être partagé\n" .
               "• Après paiement, vous serez automatiquement inscrit\n\n" .
               "Si vous avez des questions, contactez-nous au +242 06 848 45 67\n\n" .
               "Nous sommes impatients de vous accueillir à l'ISGI !\n\n" .
               "Cordialement,\nL'équipe ISGI Congo";
    } else {
        // Paiement en espèces
        return "Félicitations " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n" .
               "Votre demande d'inscription à l'ISGI a été approuvée avec succès !\n\n" .
               "📋 <strong>Numéro de dossier: " . $demande['numero_demande'] . "</strong>\n\n" .
               "✅ <strong>Votre inscription est validée</strong>\n\n" .
               "💰 <strong>Détails des frais:</strong>\n" .
               "• Frais d'inscription: " . $montant_format . " FCFA\n" .
               "• <strong>TOTAL À PAYER: " . $total_format . " FCFA</strong>\n\n" .
               "💵 <strong>Mode de paiement choisi:</strong> Espèces\n\n" .
               "📍 <strong>Procédure de paiement:</strong>\n" .
               "Veuillez vous présenter au secrétariat de l'ISGI pour effectuer votre paiement.\n\n" .
               "🏛️ <strong>Adresse du site " . $demande['site_formation'] . ":</strong>\n" .
               $this->getSiteAddress($demande['site_id'] ?? 1) . "\n\n" .
               "📅 <strong>Horaires d'ouverture:</strong>\n" .
               "Lundi - Vendredi: 8h00 - 17h00\n" .
               "Samedi: 9h00 - 13h00\n\n" .
               "⚠️ <strong>À apporter:</strong>\n" .
               "• Une copie de ce mail\n" .
               "• Votre pièce d'identité originale\n" .
               "• Le montant exact en espèces\n\n" .
               "Nous sommes impatients de vous accueillir à l'ISGI !\n\n" .
               "Cordialement,\nL'équipe ISGI Congo";
    }
}

/**
 * Obtenir l'adresse d'un site
 */
function getSiteAddress($site_id) {
    $addresses = [
        1 => "Quartier Poto-Poto, Avenue de France, Brazzaville",
        2 => "Quartier Mpita-Socoprise, Arrêt OCI, Pointe-Noire",
        3 => "Centre-ville, en diagonale de la CNSS, Ouesso"
    ];
    
    return $addresses[$site_id] ?? "Adresse non spécifiée";
}
    // Construire le corps de l'email
    $corps = $this->buildApprovalEmail($demande, $paiement_info, $lien_paiement);
    
    envoyerEmail($demande['email'], $sujet, $corps);
}
                // ACTION : VALIDER
                elseif ($action == 'valider') {
                    $check_paiement = $db->prepare("SELECT id FROM paiements WHERE demande_id = ? AND statut = 'confirme'");
                    $check_paiement->execute([$demande_id]);
                    
                    if ($check_paiement->rowCount() == 0) {
                        throw new Exception("Impossible de créer l'étudiant : paiement non confirmé");
                    }
                    
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
                    
                    $etudiant_query = "INSERT INTO etudiants 
                                      (utilisateur_id, site_id, classe_id, matricule, nom, prenom, numero_cni, 
                                       date_naissance, lieu_naissance, sexe, nationalite, adresse, ville, pays, 
                                       profession, situation_matrimoniale,
                                       nom_pere, profession_pere, nom_mere, profession_mere,
                                       telephone_parent, nom_tuteur, profession_tuteur,
                                       telephone_tuteur, lieu_service_tuteur,
                                       photo_identite, acte_naissance, releve_notes, attestation_legalisee,
                                       statut, date_inscription)
                                      VALUES (NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW())";
                    
                    $etudiant_stmt = $db->prepare($etudiant_query);
                    $success = $etudiant_stmt->execute([
                        $site_id, $matricule, $demande['nom'], $demande['prenom'], $demande['numero_cni'],
                        $demande['date_naissance'], $demande['lieu_naissance'], $demande['sexe'],
                        $demande['nationalite'] ?? 'Congolaise', $demande['adresse'], $demande['ville'],
                        $demande['pays'] ?? 'Congo', $demande['profession'], $demande['situation_matrimoniale'],
                        $demande['nom_pere'] ?? '', $demande['profession_pere'] ?? '',
                        $demande['nom_mere'] ?? '', $demande['profession_mere'] ?? '',
                        $demande['telephone_parent'] ?? '', $demande['nom_tuteur'] ?? '',
                        $demande['profession_tuteur'] ?? '', $demande['telephone_tuteur'] ?? '',
                        $demande['lieu_service_tuteur'] ?? '', $demande['photo_identite'] ?? '',
                        $demande['acte_naissance'] ?? '', $demande['releve_notes'] ?? '',
                        $demande['attestation_legalisee'] ?? ''
                    ]);
                    
                    if (!$success) {
                        $error_info = $etudiant_stmt->errorInfo();
                        throw new Exception("Erreur création étudiant: " . $error_info[2]);
                    }
                    
                    $etudiant_id = $db->lastInsertId();
                    
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
                    $message .= "🎓 Filière: " . $demande['filiere'] . "<br>";
                    $message .= "🆔 ID étudiant: <strong>$etudiant_id</strong>";
                    
                    $sujet = "Bienvenue à l'ISGI ! Votre inscription est complète";
                    $corps = "Félicitations " . $demande['prenom'] . " " . $demande['nom'] . ",\n\n";
                    $corps .= "Votre inscription à l'ISGI est maintenant complète et vous êtes officiellement étudiant(e) !\n\n";
                    $corps .= "🎓 <strong>Votre matricule étudiant: " . $matricule . "</strong>\n\n";
                    $corps .= "📚 <strong>Informations importantes:</strong>\n";
                    $corps .= "• Filière: " . $demande['filiere'] . "\n";
                    $corps .= "• Site: " . $demande['site_formation'] . "\n";
                    $corps .= "• Rentrée: " . $demande['type_rentree'] . "\n\n";
                    $corps .= "📅 <strong>Prochaines étapes:</strong>\n";
                    $corps .= "1. Vous recevrez bientôt votre emploi du temps\n";
                    $corps .= "2. La rentrée aura lieu selon le calendrier académique\n";
                    $corps .= "3. Conservez précieusement votre matricule\n\n";
                    $corps .= "Bienvenue dans la famille ISGI !\n\n";
                    $corps .= "Cordialement,\nL'équipe ISGI Congo";
                    
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
                
                header("Location: demandes.php?message=" . urlencode($message) . "&type=success");
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
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-color: #2c3e50; --secondary-color: #3498db; --accent-color: #e74c3c; --success-color: #27ae60; --warning-color: #f39c12; --info-color: #17a2b8; --bg-color: #f8f9fa; --card-bg: #ffffff; --text-color: #212529; --text-muted: #6c757d; --sidebar-bg: #2c3e50; --sidebar-text: #ffffff; --border-color: #dee2e6; }
        [data-theme="dark"] { --primary-color: #3498db; --secondary-color: #2980b9; --accent-color: #e74c3c; --success-color: #2ecc71; --warning-color: #f39c12; --info-color: #17a2b8; --bg-color: #121212; --card-bg: #1e1e1e; --text-color: #e0e0e0; --text-muted: #a0a0a0; --sidebar-bg: #1a1a1a; --sidebar-text: #ffffff; --border-color: #333333; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding: 0; min-height: 100vh; transition: background-color 0.3s ease, color 0.3s ease; }
        .app-container { display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: var(--sidebar-bg); color: var(--sidebar-text); position: fixed; height: 100vh; overflow-y: auto; transition: background-color 0.3s ease; }
        .main-content { flex: 1; margin-left: 250px; padding: 20px; min-height: 100vh; transition: background-color 0.3s ease, color 0.3s ease; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; color: var(--text-color); transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease; }
        .stat-card { text-align: center; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .stat-value { font-size: 1.8rem; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9rem; color: var(--text-muted); }
        .detail-row { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color); }
        .detail-label { font-weight: 600; color: var(--primary-color); }
        .document-card { border: 1px solid var(--border-color); border-radius: 5px; margin-bottom: 10px; }
        @media (max-width: 768px) { .sidebar { width: 70px; } .sidebar-header, .user-info, .nav-section-title, .nav-link span { display: none; } .nav-link { justify-content: center; padding: 15px; } .nav-link i { margin-right: 0; font-size: 18px; } .main-content { margin-left: 70px; padding: 15px; } }
        [data-theme="dark"] .card-header { background-color: rgba(255, 255, 255, 0.05); }
        [data-theme="dark"] .table { --bs-table-bg: var(--card-bg); --bs-table-striped-bg: rgba(255, 255, 255, 0.05); --bs-table-hover-bg: rgba(255, 255, 255, 0.1); }
        .table thead th { background-color: var(--primary-color); color: white; border: none; }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI ADMIN</h5>
                <div class="user-role">Administrateur Principal</div>
            </div>
            <div class="user-info">
                <p class="mb-1"><?php echo htmlspecialchars(SessionManager::getUserName()); ?></p>
                <small>Gestion des Demandes</small>
            </div>
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Tableau de Bord</div>
                    <a href="dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i><span>Dashboard Global</span></a>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Gestion Multi-Sites</div>
                    <a href="sites.php" class="nav-link"><i class="fas fa-building"></i><span>Tous les Sites</span></a>
                    <a href="utilisateurs.php" class="nav-link"><i class="fas fa-users"></i><span>Tous les Utilisateurs</span></a>
                    <a href="demandes.php" class="nav-link active"><i class="fas fa-user-plus"></i><span>Demandes d'Inscription</span>
                        <?php if ($stats['en_attente'] > 0): ?><span class="nav-badge"><?php echo $stats['en_attente']; ?></span><?php endif; ?>
                    </a>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()"><i class="fas fa-moon"></i> <span>Mode Sombre</span></button>
                    <a href="../../auth/logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Déconnexion</span></a>
                </div>
            </div>
        </div>
        
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
                <div class="col-md-3"><div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #f1c40f); color: white;"><div class="stat-value"><?php echo $stats['en_attente']; ?></div><div class="stat-label">En attente</div></div></div>
                <div class="col-md-3"><div class="stat-card" style="background: linear-gradient(135deg, #3498db, #2980b9); color: white;"><div class="stat-value"><?php echo $stats['en_traitement']; ?></div><div class="stat-label">En traitement</div></div></div>
                <div class="col-md-3"><div class="stat-card" style="background: linear-gradient(135deg, #2c3e50, #3498db); color: white;"><div class="stat-value"><?php echo $stats['approuvee']; ?></div><div class="stat-label">Approuvées</div></div></div>
                <div class="col-md-3"><div class="stat-card" style="background: linear-gradient(135deg, #27ae60, #2ecc71); color: white;"><div class="stat-value"><?php echo $stats['validee']; ?></div><div class="stat-label">Validées</div></div></div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filtres de recherche</h5></div>
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3"><label class="form-label">Statut</label><select name="statut" class="form-select"><option value="tous" <?php echo $statut_filter == 'tous' ? 'selected' : ''; ?>>Tous les statuts</option><option value="en_attente" <?php echo $statut_filter == 'en_attente' ? 'selected' : ''; ?>>En attente</option><option value="en_traitement" <?php echo $statut_filter == 'en_traitement' ? 'selected' : ''; ?>>En traitement</option><option value="approuvee" <?php echo $statut_filter == 'approuvee' ? 'selected' : ''; ?>>Approuvées</option><option value="validee" <?php echo $statut_filter == 'validee' ? 'selected' : ''; ?>>Validées</option><option value="rejetee" <?php echo $statut_filter == 'rejetee' ? 'selected' : ''; ?>>Rejetées</option></select></div>
                        <div class="col-md-3"><label class="form-label">Site</label><select name="site" class="form-select"><option value="">Tous les sites</option><?php foreach($sites as $site): ?><option value="<?php echo $site['id']; ?>" <?php echo $site_filter == $site['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($site['nom'] . ' - ' . $site['ville']); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-2"><label class="form-label">Date début</label><input type="date" name="date_debut" class="form-control" value="<?php echo htmlspecialchars($date_debut); ?>"></div>
                        <div class="col-md-2"><label class="form-label">Date fin</label><input type="date" name="date_fin" class="form-control" value="<?php echo htmlspecialchars($date_fin); ?>"></div>
                        <div class="col-md-2"><label class="form-label">Recherche</label><input type="text" name="search" class="form-control" placeholder="Nom, email..." value="<?php echo htmlspecialchars($search); ?>"></div>
                        <div class="col-md-12"><div class="d-flex justify-content-between mt-3"><button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Filtrer</button><a href="demandes.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i>Réinitialiser</a></div></div>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-list me-2"></i>Liste des Demandes (<?php echo count($demandes); ?>)</h5></div>
                <div class="card-body">
                    <?php if(empty($demandes)): ?>
                    <div class="alert alert-info text-center"><i class="fas fa-info-circle fa-2x mb-3"></i><h5>Aucune demande trouvée</h5><p class="mb-0">Aucune demande ne correspond aux critères de recherche.</p></div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>ID</th><th>Demandeur</th><th>Email</th><th>Filière</th><th>Site</th><th>Date demande</th><th>Statut</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($demandes as $demande): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($demande['numero_demande']); ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($demande['telephone']); ?></small></td>
                                    <td><?php echo htmlspecialchars($demande['email']); ?></td>
                                    <td><?php echo htmlspecialchars($demande['filiere']); ?><br><small class="text-muted"><?php echo htmlspecialchars($demande['niveau']); ?></small></td>
                                    <td><?php echo htmlspecialchars($demande['site_nom'] ?? 'Non assigné'); ?></td>
                                    <td><?php echo formatDateFr($demande['date_demande'], 'd/m/Y H:i'); ?><br><small class="text-muted"><?php $date1 = new DateTime($demande['date_demande']); $date2 = new DateTime(); $interval = $date1->diff($date2); echo $interval->days . ' jour(s)'; ?></small></td>
                                    <td><?php echo getStatutBadge($demande['statut']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $demande['id']; ?>"><i class="fas fa-eye"></i></button>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick="telechargerDocuments(<?php echo $demande['id']; ?>, '<?php echo addslashes($demande['nom'] . ' ' . $demande['prenom']); ?>')"><i class="fas fa-download"></i></button>
                                            <?php if($demande['statut'] == 'en_attente' || $demande['statut'] == 'en_traitement'): ?>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#approveModal<?php echo $demande['id']; ?>"><i class="fas fa-check-circle"></i> Approuver</button>
                                                <?php if($demande['statut'] == 'en_attente'): ?><button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#processModal<?php echo $demande['id']; ?>"><i class="fas fa-cogs"></i> Traiter</button><?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?php echo $demande['id']; ?>"><i class="fas fa-times"></i> Rejeter</button>
                                            </div>
                                            <?php elseif($demande['statut'] == 'approuvee'): ?>
                                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#validateModal<?php echo $demande['id']; ?>"><i class="fas fa-user-check"></i> Valider étudiant</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                
                                <!-- Modal Vue détaillée -->
                                <div class="modal fade" id="viewModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <div class="modal-header"><h5 class="modal-title">Détails de la demande</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <div class="modal-body">
                                                <div class="demande-details">
                                                    <h6 class="mb-3">Informations personnelles</h6>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="detail-row"><div class="detail-label">Nom complet</div><div><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Date de naissance</div><div><?php echo formatDateFr($demande['date_naissance']); ?> à <?php echo htmlspecialchars($demande['lieu_naissance']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Sexe</div><div><?php echo htmlspecialchars($demande['sexe']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">CNI</div><div><?php echo htmlspecialchars($demande['numero_cni']); ?></div></div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="detail-row"><div class="detail-label">Adresse</div><div><?php echo htmlspecialchars($demande['adresse'] . ', ' . $demande['ville'] . ', ' . $demande['pays']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Téléphone</div><div><?php echo htmlspecialchars($demande['telephone']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Email</div><div><?php echo htmlspecialchars($demande['email']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Profession</div><div><?php echo htmlspecialchars($demande['profession']); ?></div></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <h6 class="mb-3 mt-4">Informations académiques</h6>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="detail-row"><div class="detail-label">Cycle</div><div><?php echo htmlspecialchars($demande['cycle_formation']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Domaine</div><div><?php echo htmlspecialchars($demande['domaine']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Filière</div><div><?php echo htmlspecialchars($demande['filiere']); ?></div></div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="detail-row"><div class="detail-label">Niveau</div><div><?php echo htmlspecialchars($demande['niveau']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Type rentrée</div><div><?php echo htmlspecialchars($demande['type_rentree']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Site formation</div><div><?php echo htmlspecialchars($demande['site_formation']); ?></div></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <h6 class="mb-3 mt-4">Documents téléchargés</h6>
                                                    <div class="row">
                                                        <?php
                                                        $documents = [
                                                            'Photo d\'identité' => $demande['photo_identite'],
                                                            'Acte de naissance' => $demande['acte_naissance'],
                                                            'Relevé de notes' => $demande['releve_notes'],
                                                            'Attestation légalisée' => $demande['attestation_legalisee']
                                                        ];
                                                        
                                                        foreach ($documents as $label => $fichier):
                                                            if (!empty($fichier)):
                                                                $chemin_complet = ROOT_PATH . '/' . $fichier;
                                                                $taille = file_exists($chemin_complet) ? filesize($chemin_complet) : 0;
                                                        ?>
                                                        <div class="col-md-6 mb-2">
                                                            <div class="document-card">
                                                                <div class="card-body">
                                                                    <div class="d-flex justify-content-between align-items-center">
                                                                        <div>
                                                                            <strong class="d-block"><?php echo $label; ?></strong>
                                                                            <small class="text-muted"><?php echo formatTaille($taille); ?></small>
                                                                            <div class="text-truncate" style="max-width: 200px;"><small><?php echo htmlspecialchars(basename($fichier)); ?></small></div>
                                                                        </div>
                                                                        <div class="btn-group">
                                                                            <?php if (file_exists(ROOT_PATH . '/' . $fichier)): ?>
                                                                                <a href="view_document.php?file=<?php echo urlencode($fichier); ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Visualiser"><i class="fas fa-eye"></i></a>
                                                                                <a href="view_document.php?file=<?php echo urlencode($fichier); ?>&download=1" class="btn btn-sm btn-outline-secondary" title="Télécharger"><i class="fas fa-download"></i></a>
                                                                            <?php else: ?>
                                                                                <span class="btn btn-sm btn-outline-danger disabled" title="Fichier non trouvé"><i class="fas fa-exclamation-triangle"></i></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php endif; endforeach; ?>
                                                    </div>
                                                    
                                                    <h6 class="mb-3 mt-4">Informations administratives</h6>
                                                    <div class="row">
                                                        <div class="col-md-6">
                                                            <div class="detail-row"><div class="detail-label">Numéro demande</div><div><?php echo htmlspecialchars($demande['numero_demande']); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Date demande</div><div><?php echo formatDateFr($demande['date_demande'], 'd/m/Y H:i'); ?></div></div>
                                                            <div class="detail-row"><div class="detail-label">Statut</div><div><?php echo getStatutBadge($demande['statut']); ?></div></div>
                                                            <?php if($demande['mode_paiement']): ?><div class="detail-row"><div class="detail-label">Mode de paiement</div><div><?php echo htmlspecialchars($demande['mode_paiement']); ?></div></div><?php endif; ?>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <?php if($demande['date_traitement']): ?><div class="detail-row"><div class="detail-label">Date traitement</div><div><?php echo formatDateFr($demande['date_traitement']); ?></div></div><?php endif; ?>
                                                            <?php if($demande['date_validation']): ?><div class="detail-row"><div class="detail-label">Date validation</div><div><?php echo formatDateFr($demande['date_validation']); ?></div></div><?php endif; ?>
                                                            <?php if($demande['commentaire_admin']): ?><div class="detail-row"><div class="detail-label">Commentaire admin</div><div><?php echo htmlspecialchars($demande['commentaire_admin']); ?></div></div><?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if($demande['admin_traitant_nom'] || $demande['validateur_nom']): ?>
                                                    <h6 class="mb-3 mt-4">Traçabilité</h6>
                                                    <div class="row">
                                                        <?php if($demande['admin_traitant_nom']): ?>
                                                        <div class="col-md-6">
                                                            <div class="detail-row"><div class="detail-label">Traité par</div><div><?php echo htmlspecialchars($demande['admin_traitant_nom']); ?></div>
                                                            <?php if($demande['date_traitement']): ?><small class="text-muted"><?php echo formatDateFr($demande['date_traitement'], 'd/m/Y H:i'); ?></small><?php endif; ?></div>
                                                        </div>
                                                        <?php endif; ?>
                                                        <?php if($demande['validateur_nom']): ?>
                                                        <div class="col-md-6">
                                                            <div class="detail-row"><div class="detail-label">Validé par</div><div><?php echo htmlspecialchars($demande['validateur_nom']); ?></div>
                                                            <?php if($demande['date_validation']): ?><small class="text-muted"><?php echo formatDateFr($demande['date_validation'], 'd/m/Y H:i'); ?></small><?php endif; ?></div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Modal Traiter -->
                                <div class="modal fade" id="processModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog"><div class="modal-content">
                                        <form method="POST" action=""><div class="modal-header"><h5 class="modal-title">Traiter la demande</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body"><p>Êtes-vous sûr de vouloir traiter la demande de <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong> ?</p>
                                        <input type="hidden" name="demande_id" value="<?php echo $demande['id']; ?>"><input type="hidden" name="action" value="traiter">
                                        <div class="mb-3"><label class="form-label">Commentaire (optionnel)</label><textarea name="commentaire" class="form-control" rows="3" placeholder="Ajoutez un commentaire..."></textarea></div></div>
                                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-warning"><i class="fas fa-cogs me-2"></i>Mettre en traitement</button></div></form>
                                    </div></div>
                                </div>
                                
                                <!-- Modal Approuver -->
                                <div class="modal fade" id="approveModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog"><div class="modal-content">
                                        <form method="POST" action=""><div class="modal-header"><h5 class="modal-title">Approuver la demande</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body"><div class="alert alert-info"><i class="fas fa-info-circle"></i> Cette action approuvera la demande. L'étudiant recevra un email avec les instructions de paiement.</div>
                                        <p>Approuver la demande de <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong> ?</p>
                                        <p><strong>Mode de paiement choisi :</strong> <?php echo htmlspecialchars($demande['mode_paiement']); ?></p>
                                        <input type="hidden" name="demande_id" value="<?php echo $demande['id']; ?>"><input type="hidden" name="action" value="approuver">
                                        <div class="mb-3"><label class="form-label">Commentaire (optionnel)</label><textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire d'approbation..."></textarea></div></div>
                                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary"><i class="fas fa-check-circle me-2"></i>Approuver et envoyer email</button></div></form>
                                    </div></div>
                                </div>
                                
                                <!-- Modal Valider étudiant -->
                                <div class="modal fade" id="validateModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog"><div class="modal-content">
                                        <form method="POST" action=""><div class="modal-header"><h5 class="modal-title">Valider et créer l'étudiant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body"><div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Cette action créera un étudiant dans la base de données. Assurez-vous que le paiement a été confirmé.</div>
                                        <p>Valider la demande et créer l'étudiant <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong> ?</p>
                                        <input type="hidden" name="demande_id" value="<?php echo $demande['id']; ?>"><input type="hidden" name="action" value="valider">
                                        <div class="mb-3"><label class="form-label">Commentaire (optionnel)</label><textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire de validation..."></textarea></div></div>
                                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-success"><i class="fas fa-user-check me-2"></i>Valider et créer étudiant</button></div></form>
                                    </div></div>
                                </div>
                                
                                <!-- Modal Rejeter -->
                                <div class="modal fade" id="rejectModal<?php echo $demande['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog"><div class="modal-content">
                                        <form method="POST" action=""><div class="modal-header"><h5 class="modal-title">Rejeter la demande</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body"><p>Rejeter la demande de <strong><?php echo htmlspecialchars($demande['nom'] . ' ' . $demande['prenom']); ?></strong> ?</p>
                                        <input type="hidden" name="demande_id" value="<?php echo $demande['id']; ?>"><input type="hidden" name="action" value="rejeter">
                                        <div class="mb-3"><label class="form-label">Raison du rejet <span class="text-danger">*</span></label><textarea name="raison_rejet" class="form-control" rows="3" placeholder="Expliquez la raison du rejet..." required></textarea></div>
                                        <div class="mb-3"><label class="form-label">Commentaire (optionnel)</label><textarea name="commentaire" class="form-control" rows="3" placeholder="Commentaire additionnel..."></textarea></div></div>
                                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-danger"><i class="fas fa-times me-2"></i>Rejeter</button></div></form>
                                    </div></div>
                                </div>
                                
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <nav aria-label="Pagination"><ul class="pagination justify-content-center"><li class="page-item disabled"><a class="page-link" href="#">Précédent</a></li><li class="page-item active"><a class="page-link" href="#">1</a></li><li class="page-item"><a class="page-link" href="#">2</a></li><li class="page-item"><a class="page-link" href="#">3</a></li><li class="page-item"><a class="page-link" href="#">Suivant</a></li></ul></nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('isgi_theme', newTheme);
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            themeButton.innerHTML = newTheme === 'dark' ? '<i class="fas fa-sun"></i> <span>Mode Clair</span>' : '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
        }
    }
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
    document.addEventListener('DOMContentLoaded', function() {
        const theme = localStorage.getItem('isgi_theme') || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            themeButton.innerHTML = theme === 'dark' ? '<i class="fas fa-sun"></i> <span>Mode Clair</span>' : '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
        }
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    });
    </script>
</body>
</html>