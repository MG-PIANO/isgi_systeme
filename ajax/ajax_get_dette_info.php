<?php
// ajax_get_dette_info.php
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
@include_once ROOT_PATH . '/config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['id'])) {
    $dette_id = intval($_GET['id']);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        $sql = "SELECT d.*, 
                e.matricule, CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
                aa.libelle as annee_academique
                FROM dettes d
                JOIN etudiants e ON d.etudiant_id = e.id
                JOIN annees_academiques aa ON d.annee_academique_id = aa.id
                WHERE d.id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$dette_id]);
        $dette = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($dette) {
            echo json_encode([
                'success' => true,
                'matricule' => $dette['matricule'],
                'etudiant_nom' => $dette['etudiant_nom'],
                'annee_academique' => $dette['annee_academique'],
                'montant_du' => $dette['montant_du'],
                'montant_paye' => $dette['montant_paye'],
                'montant_restant' => $dette['montant_restant'],
                'date_limite' => $dette['date_limite'],
                'statut' => $dette['statut']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Dette non trouvée'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur: ' . $e->getMessage()
        ]);
    }
}
?>