<?php
define('ROOT_PATH', dirname(dirname(dirname(dirname(__FILE__)))));
require_once ROOT_PATH . '/config/database.php';

session_start();
$db = Database::getInstance()->getConnection();
$salle_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($salle_id === 0) {
    die('<div class="alert alert-danger">Salle non spécifiée</div>');
}

try {
    $query = "SELECT 
                s.*,
                st.nom as site_nom
              FROM salles s
              LEFT JOIN sites st ON s.site_id = st.id
              WHERE s.id = :salle_id";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':salle_id' => $salle_id]);
    $salle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$salle) {
        die('<div class="alert alert-danger">Salle non trouvée</div>');
    }
    
    // Fonction pour afficher le badge de statut
    function getStatutBadge($statut) {
        switch ($statut) {
            case 'disponible':
                return '<span class="badge bg-success">Disponible</span>';
            case 'occupee':
                return '<span class="badge bg-danger">Occupée</span>';
            case 'maintenance':
                return '<span class="badge bg-warning">Maintenance</span>';
            case 'reservee':
                return '<span class="badge bg-info">Réservée</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($statut) . '</span>';
        }
    }
    
    // Fonction pour afficher le badge de type
    function getTypeBadge($type) {
        switch ($type) {
            case 'classe':
                return '<span class="badge bg-primary">Salle de classe</span>';
            case 'amphi':
                return '<span class="badge bg-secondary">Amphithéâtre</span>';
            case 'labo':
                return '<span class="badge bg-info">Laboratoire</span>';
            case 'bureau':
                return '<span class="badge bg-success">Bureau</span>';
            case 'salle_examen':
                return '<span class="badge bg-warning">Salle d\'examen</span>';
            case 'autre':
                return '<span class="badge bg-dark">Autre</span>';
            default:
                return '<span class="badge bg-secondary">' . htmlspecialchars($type) . '</span>';
        }
    }
    
    echo '
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="salle-icon text-primary mb-3" style="font-size: 4rem;">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <h4>' . htmlspecialchars($salle['nom']) . '</h4>
                    <div class="mb-3">' . getStatutBadge($salle['statut']) . ' ' . getTypeBadge($salle['type_salle']) . '</div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5>Informations détaillées</h5>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-building me-2"></i>Bâtiment :</strong><br>
                            ' . htmlspecialchars($salle['batiment'] ?? 'Non spécifié') . '</p>
                            
                            <p><strong><i class="fas fa-users me-2"></i>Capacité :</strong><br>
                            ' . htmlspecialchars($salle['capacite']) . ' places</p>
                            
                            <p><strong><i class="fas fa-ruler-combined me-2"></i>Superficie :</strong><br>
                            ' . htmlspecialchars($salle['superficie'] ?? '?') . ' m²</p>
                        </div>
                        
                        <div class="col-md-6">
                            <p><strong><i class="fas fa-school me-2"></i>Site :</strong><br>
                            ' . htmlspecialchars($salle['site_nom']) . '</p>
                            
                            <p><strong><i class="fas fa-calendar-plus me-2"></i>Créée le :</strong><br>
                            ' . date('d/m/Y', strtotime($salle['date_creation'])) . '</p>
                            
                            <p><strong><i class="fas fa-sync-alt me-2"></i>Dernière modification :</strong><br>
                            ' . date('d/m/Y H:i', strtotime($salle['date_modification'])) . '</p>
                        </div>
                    </div>
                    
                    <h5 class="mt-4">Équipements</h5>
                    <p>' . nl2br(htmlspecialchars($salle['equipements'] ?? 'Aucun équipement spécifié')) . '</p>
                    
                    <h5 class="mt-4">Description</h5>
                    <p>' . nl2br(htmlspecialchars($salle['description'] ?? 'Aucune description')) . '</p>';
                    
                    if ($salle['qr_code']) {
                        echo '
                        <h5 class="mt-4">QR Code</h5>
                        <img src="' . htmlspecialchars($salle['qr_code']) . '" alt="QR Code de la salle" class="img-fluid" style="max-width: 200px;">';
                    }
                    
                    echo '
                </div>
            </div>
        </div>
    </div>';
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Erreur: ' . htmlspecialchars($e->getMessage()) . '</div>';
}