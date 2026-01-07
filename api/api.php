<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration de la base de données
$host = 'localhost';
$dbname = 'isgi_systeme';
$username = 'root';
$password = 'admin1234';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur de connexion: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

switch($action) {
    case 'getDashboardStats':
        getDashboardStats($pdo);
        break;
    case 'getRecentDemandes':
        getRecentDemandes($pdo);
        break;
    case 'getEtudiants':
        getEtudiants($pdo);
        break;
    case 'getDemandes':
        getDemandes($pdo);
        break;
    case 'getReinscriptions':
        getReinscriptions($pdo);
        break;
    case 'getDettes':
        getDettes($pdo);
        break;
    case 'getPaymentsHistory':
        getPaymentsHistory($pdo);
        break;
    case 'getStudentDetails':
        getStudentDetails($pdo);
        break;
    case 'getDemandeDetails':
        getDemandeDetails($pdo);
        break;
    case 'updateDemandeStatus':
        updateDemandeStatus($pdo);
        break;
    case 'getSites':
        getSites($pdo);
        break;
    case 'getFilieres':
        getFilieres($pdo);
        break;
    case 'getTypesFrais':
        getTypesFrais($pdo);
        break;
    case 'getSiteStats':
        getSiteStats($pdo);
        break;
    case 'getFiliereStats':
        getFiliereStats($pdo);
        break;
    case 'processPayment':
        processPayment($pdo);
        break;
    case 'sendReminder':
        sendReminder($pdo);
        break;
    case 'getCalendarEvents':
        getCalendarEvents($pdo);
        break;
    case 'getRevenueStats':
        getRevenueStats($pdo);
        break;
    case 'getPaymentDistribution':
        getPaymentDistribution($pdo);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
}

function getDashboardStats($pdo) {
    $site_id = $_GET['site_id'] ?? null;
    
    $stats = [];
    
    // Total étudiants actifs
    $sql = "SELECT COUNT(*) as total FROM etudiants WHERE statut = 'actif'";
    if ($site_id) $sql .= " AND site_id = ?";
    $stmt = $pdo->prepare($sql);
    if ($site_id) $stmt->execute([$site_id]);
    else $stmt->execute();
    $stats['total_etudiants'] = $stmt->fetch()['total'];
    
    // Demandes en attente
    $sql = "SELECT COUNT(*) as total FROM demande_inscriptions WHERE statut = 'en_attente'";
    if ($site_id) $sql .= " AND site_id = ?";
    $stmt = $pdo->prepare($sql);
    if ($site_id) $stmt->execute([$site_id]);
    else $stmt->execute();
    $stats['demandes_en_attente'] = $stmt->fetch()['total'];
    
    // Réinscriptions cette année
    $currentYear = date('Y');
    $sql = "SELECT COUNT(*) as total FROM reinscriptions WHERE YEAR(date_demande) = ?";
    if ($site_id) $sql .= " AND site_id = ?";
    $stmt = $pdo->prepare($sql);
    if ($site_id) $stmt->execute([$currentYear, $site_id]);
    else $stmt->execute([$currentYear]);
    $stats['reinscriptions'] = $stmt->fetch()['total'];
    
    // Total dettes
    $sql = "SELECT SUM(montant_restant) as total FROM dettes WHERE statut = 'en_cours' OR statut = 'en_retard'";
    if ($site_id) {
        $sql .= " AND etudiant_id IN (SELECT id FROM etudiants WHERE site_id = ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    $stats['total_dettes'] = $stmt->fetch()['total'] ?? 0;
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

function getRecentDemandes($pdo) {
    $limit = $_GET['limit'] ?? 10;
    $site_id = $_GET['site_id'] ?? null;
    
    $sql = "SELECT * FROM demande_inscriptions ORDER BY date_demande DESC LIMIT ?";
    $params = [$limit];
    
    if ($site_id) {
        $sql = "SELECT * FROM demande_inscriptions WHERE site_id = ? ORDER BY date_demande DESC LIMIT ?";
        $params = [$site_id, $limit];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $demandes]);
}

function getEtudiants($pdo) {
    $site_id = $_GET['site_id'] ?? null;
    $statut = $_GET['statut'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $sql = "SELECT * FROM etudiants WHERE 1=1";
    $params = [];
    
    if ($site_id) {
        $sql .= " AND site_id = ?";
        $params[] = $site_id;
    }
    
    if ($statut) {
        $sql .= " AND statut = ?";
        $params[] = $statut;
    }
    
    if ($search) {
        $sql .= " AND (nom LIKE ? OR prenom LIKE ? OR matricule LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY nom, prenom";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $etudiants]);
}

function getDemandes($pdo) {
    $statut = $_GET['statut'] ?? null;
    $site_id = $_GET['site_id'] ?? null;
    $cycle = $_GET['cycle'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $sql = "SELECT * FROM demande_inscriptions WHERE 1=1";
    $params = [];
    
    if ($statut) {
        $sql .= " AND statut = ?";
        $params[] = $statut;
    }
    
    if ($site_id) {
        $sql .= " AND site_id = ?";
        $params[] = $site_id;
    }
    
    if ($cycle) {
        $sql .= " AND cycle_formation LIKE ?";
        $params[] = "%$cycle%";
    }
    
    if ($search) {
        $sql .= " AND (nom LIKE ? OR prenom LIKE ? OR numero_demande LIKE ? OR email LIKE ?)";
        $searchTerm = "%$search%";
        for ($i = 0; $i < 4; $i++) {
            $params[] = $searchTerm;
        }
    }
    
    $sql .= " ORDER BY date_demande DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $demandes]);
}

function getReinscriptions($pdo) {
    $annee = $_GET['annee'] ?? null;
    $site_id = $_GET['site_id'] ?? null;
    $search = $_GET['search'] ?? null;
    
    $sql = "SELECT r.*, e.nom, e.prenom FROM reinscriptions r 
            LEFT JOIN etudiants e ON r.etudiant_id = e.id 
            WHERE 1=1";
    $params = [];
    
    if ($annee) {
        $sql .= " AND r.annee_academique LIKE ?";
        $params[] = "%$annee%";
    }
    
    if ($site_id) {
        $sql .= " AND r.site_formation = (SELECT nom FROM sites WHERE id = ?)";
        $params[] = $site_id;
    }
    
    if ($search) {
        $sql .= " AND (r.matricule LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?)";
        $searchTerm = "%$search%";
        for ($i = 0; $i < 3; $i++) {
            $params[] = $searchTerm;
        }
    }
    
    $sql .= " ORDER BY r.date_demande DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reinscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $reinscriptions]);
}

function getDettes($pdo) {
    $site_id = $_GET['site_id'] ?? null;
    
    $sql = "SELECT d.*, e.nom, e.prenom, e.site_id 
            FROM dettes d 
            JOIN etudiants e ON d.etudiant_id = e.id 
            WHERE (d.statut = 'en_cours' OR d.statut = 'en_retard')";
    $params = [];
    
    if ($site_id) {
        $sql .= " AND e.site_id = ?";
        $params[] = $site_id;
    }
    
    $sql .= " ORDER BY d.date_limite";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $dettes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $dettes]);
}

function getPaymentsHistory($pdo) {
    $limit = $_GET['limit'] ?? 20;
    $site_id = $_GET['site_id'] ?? null;
    
    $sql = "SELECT p.*, e.nom, e.prenom 
            FROM paiements p 
            JOIN etudiants e ON p.etudiant_id = e.id 
            WHERE 1=1";
    $params = [];
    
    if ($site_id) {
        $sql .= " AND e.site_id = ?";
        $params[] = $site_id;
    }
    
    $sql .= " ORDER BY p.date_paiement DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $payments]);
}

function getStudentDetails($pdo) {
    $id = $_GET['id'];
    
    $stmt = $pdo->prepare("SELECT * FROM etudiants WHERE id = ?");
    $stmt->execute([$id]);
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $etudiant]);
}

function getDemandeDetails($pdo) {
    $id = $_GET['id'];
    
    $stmt = $pdo->prepare("SELECT * FROM demande_inscriptions WHERE id = ?");
    $stmt->execute([$id]);
    $demande = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $demande]);
}

function updateDemandeStatus($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $sql = "UPDATE demande_inscriptions SET 
            statut = ?, 
            admin_traitant_id = ?,
            date_traitement = NOW()";
    
    $params = [$data['statut'], $data['admin_id']];
    
    if ($data['statut'] === 'rejetee' && isset($data['raison_rejet'])) {
        $sql .= ", raison_rejet = ?";
        $params[] = $data['raison_rejet'];
    } elseif ($data['statut'] === 'validee') {
        $sql .= ", date_validation = NOW(), date_creation_compte = NOW()";
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $data['id'];
    
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute($params);
    
    echo json_encode(['success' => $success]);
}

function getSites($pdo) {
    $stmt = $pdo->query("SELECT * FROM sites WHERE statut = 'actif' ORDER BY nom");
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $sites]);
}

function getFilieres($pdo) {
    $stmt = $pdo->query("SELECT * FROM filieres ORDER BY nom");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $filieres]);
}

function getTypesFrais($pdo) {
    $stmt = $pdo->query("SELECT * FROM types_frais ORDER BY nom");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $types]);
}

function getSiteStats($pdo) {
    $stmt = $pdo->query("SELECT s.id, s.nom, COUNT(e.id) as total_etudiants 
                        FROM sites s 
                        LEFT JOIN etudiants e ON s.id = e.site_id AND e.statut = 'actif' 
                        GROUP BY s.id, s.nom 
                        ORDER BY s.nom");
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

function getFiliereStats($pdo) {
    $stmt = $pdo->query("SELECT f.id, f.nom, COUNT(i.id) as total_etudiants 
                        FROM filieres f 
                        LEFT JOIN inscriptions i ON f.id = i.filiere_id 
                        GROUP BY f.id, f.nom 
                        ORDER BY total_etudiants DESC 
                        LIMIT 10");
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $stats]);
}

function processPayment($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $pdo->beginTransaction();
        
        // Insérer le paiement
        $sql = "INSERT INTO paiements (etudiant_id, type_frais_id, annee_academique_id, reference, 
                montant, mode_paiement, date_paiement, caissier_id, statut) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'valide')";
        
        // Déterminer l'année académique actuelle
        $annee_academique_id = getCurrentAcademicYear($pdo);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['etudiant_id'],
            $data['type_frais_id'],
            $annee_academique_id,
            $data['reference'],
            $data['montant'],
            $data['mode_paiement'],
            $data['date_paiement'],
            $data['caissier_id']
        ]);
        
        // Mettre à jour la dette de l'étudiant
        updateStudentDebt($pdo, $data['etudiant_id'], $annee_academique_id);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getCurrentAcademicYear($pdo) {
    $stmt = $pdo->query("SELECT id FROM annees_academiques WHERE statut = 'active' LIMIT 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['id'] : 1; // Par défaut 1
}

function updateStudentDebt($pdo, $etudiant_id, $annee_academique_id) {
    // Calculer le nouveau montant restant
    $sql = "SELECT montant_du, montant_paye FROM dettes 
            WHERE etudiant_id = ? AND annee_academique_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$etudiant_id, $annee_academique_id]);
    $dette = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dette) {
        $nouveau_paye = $dette['montant_paye'] + $_POST['montant'];
        $nouveau_restant = $dette['montant_du'] - $nouveau_paye;
        $statut = $nouveau_restant <= 0 ? 'soldee' : 'en_cours';
        
        $sql = "UPDATE dettes SET 
                montant_paye = ?,
                montant_restant = ?,
                statut = ? 
                WHERE etudiant_id = ? AND annee_academique_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nouveau_paye, $nouveau_restant, $statut, $etudiant_id, $annee_academique_id]);
    }
}

function sendReminder($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Récupérer les informations de l'étudiant
    $sql = "SELECT e.*, d.montant_restant, d.date_limite 
            FROM etudiants e 
            JOIN dettes d ON e.id = d.etudiant_id 
            WHERE e.id = ? AND d.statut IN ('en_cours', 'en_retard')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['etudiant_id']]);
    $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($etudiant) {
        // En réalité, ici vous enverriez un email ou SMS
        // Pour l'exemple, on simule juste l'envoi
        
        // Ajouter une notification dans les logs
        $sql = "INSERT INTO notifications (utilisateur_id, type, titre, message) 
                VALUES (?, 'warning', 'Rappel de paiement', 
                'Rappel envoyé pour dette de {$etudiant['montant_restant']} FCFA')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data['admin_id']]);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Étudiant non trouvé ou pas de dette']);
    }
}

function getCalendarEvents($pdo) {
    $stmt = $pdo->query("SELECT * FROM calendrier_academique WHERE statut = 'planifie' OR statut = 'en_cours' 
                        ORDER BY date_debut_cours");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $events]);
}

function getRevenueStats($pdo) {
    $site_id = $_GET['site_id'] ?? null;
    
    // Récupérer les revenus des 12 derniers mois
    $sql = "SELECT MONTH(date_paiement) as mois, SUM(montant) as total 
            FROM paiements p 
            JOIN etudiants e ON p.etudiant_id = e.id 
            WHERE date_paiement >= DATE_SUB(NOW(), INTERVAL 12 MONTH)";
    
    if ($site_id) {
        $sql .= " AND e.site_id = ?";
    }
    
    $sql .= " GROUP BY MONTH(date_paiement) ORDER BY mois";
    
    $stmt = $pdo->prepare($sql);
    if ($site_id) {
        $stmt->execute([$site_id]);
    } else {
        $stmt->execute();
    }
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Créer un tableau pour les 12 derniers mois
    $revenues = array_fill(0, 12, 0);
    foreach ($results as $row) {
        $revenues[$row['mois'] - 1] = (float)$row['total'];
    }
    
    echo json_encode(['success' => true, 'data' => $revenues]);
}

function getPaymentDistribution($pdo) {
    $site_id = $_GET['site_id'] ?? null;
    
    $sql = "SELECT mode_paiement, COUNT(*) as count, SUM(montant) as total 
            FROM paiements p 
            JOIN etudiants e ON p.etudiant_id = e.id 
            WHERE 1=1";
    
    if ($site_id) {
        $sql .= " AND e.site_id = ?";
    }
    
    $sql .= " GROUP BY mode_paiement";
    
    $stmt = $pdo->prepare($sql);
    if ($site_id) {
        $stmt->execute([$site_id]);
    } else {
        $stmt->execute();
    }
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $data = [];
    
    foreach ($results as $row) {
        $labels[] = $row['mode_paiement'];
        $data[] = (float)$row['total'];
    }
    
    echo json_encode(['success' => true, 'labels' => $labels, 'data' => $data]);
}
?>