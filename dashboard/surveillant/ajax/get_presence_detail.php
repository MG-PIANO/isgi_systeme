<?php
// dashboard/surveillant/ajax/get_presence_detail.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    exit('Accès non autorisé');
}

$db = Database::getInstance()->getConnection();
$presence_id = $_GET['id'] ?? 0;

$query = "
    SELECT 
        p.*,
        e.matricule,
        e.nom,
        e.prenom,
        e.photo_identite,
        c.nom as classe_nom,
        m.nom as matiere_nom,
        m.code as matiere_code,
        u1.nom as surveillant_nom,
        u1.prenom as surveillant_prenom,
        u2.nom as createur_nom,
        u2.prenom as createur_prenom
    FROM presences p
    LEFT JOIN etudiants e ON p.etudiant_id = e.id
    LEFT JOIN classes c ON e.classe_id = c.id
    LEFT JOIN matieres m ON p.matiere_id = m.id
    LEFT JOIN utilisateurs u1 ON p.surveillant_id = u1.id
    LEFT JOIN utilisateurs u2 ON p.surveillant_id = u2.id
    WHERE p.id = :id
";

$stmt = $db->prepare($query);
$stmt->execute([':id' => $presence_id]);
$presence = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$presence) {
    echo '<div class="alert alert-danger">Présence non trouvée</div>';
    exit();
}

function formatDateFr($date, $format = 'd/m/Y H:i:s') {
    return date($format, strtotime($date));
}

function getTypePresenceText($type) {
    $types = [
        'entree_ecole' => 'Entrée dans l\'école',
        'sortie_ecole' => 'Sortie de l\'école',
        'entree_classe' => 'Entrée en classe',
        'sortie_classe' => 'Sortie de classe'
    ];
    return $types[$type] ?? $type;
}

function getStatutText($statut) {
    $statuts = [
        'present' => 'Présent',
        'absent' => 'Absent',
        'retard' => 'En retard',
        'justifie' => 'Absence justifiée'
    ];
    return $statuts[$statut] ?? $statut;
}
?>

<div class="row">
    <div class="col-md-4 text-center">
        <div class="mb-3">
            <i class="fas fa-user-graduate fa-4x text-primary"></i>
        </div>
        <h4><?php echo htmlspecialchars($presence['nom'] . ' ' . $presence['prenom']); ?></h4>
        <p class="text-muted">
            Matricule: <strong><?php echo htmlspecialchars($presence['matricule']); ?></strong><br>
            Classe: <?php echo htmlspecialchars($presence['classe_nom']); ?>
        </p>
    </div>
    
    <div class="col-md-8">
        <div class="row">
            <div class="col-6">
                <div class="mb-3">
                    <label class="form-label text-muted">Type de présence</label>
                    <div class="form-control bg-light">
                        <i class="fas fa-door-open me-2"></i>
                        <?php echo getTypePresenceText($presence['type_presence']); ?>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="mb-3">
                    <label class="form-label text-muted">Statut</label>
                    <div class="form-control bg-light">
                        <i class="fas fa-<?php echo $presence['statut'] == 'present' ? 'check-circle text-success' : ($presence['statut'] == 'absent' ? 'times-circle text-danger' : 'clock text-warning'); ?> me-2"></i>
                        <?php echo getStatutText($presence['statut']); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-6">
                <div class="mb-3">
                    <label class="form-label text-muted">Date et Heure</label>
                    <div class="form-control bg-light">
                        <i class="fas fa-calendar-alt me-2"></i>
                        <?php echo formatDateFr($presence['date_heure']); ?>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="mb-3">
                    <label class="form-label text-muted">Matière</label>
                    <div class="form-control bg-light">
                        <i class="fas fa-book me-2"></i>
                        <?php echo $presence['matiere_nom'] ? htmlspecialchars($presence['matiere_nom']) : 'Non spécifiée'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-6">
                <div class="mb-3">
                    <label class="form-label text-muted">Enregistré par</label>
                    <div class="form-control bg-light">
                        <i class="fas fa-user-shield me-2"></i>
                        <?php echo $presence['surveillant_nom'] ? 
                            htmlspecialchars($presence['surveillant_nom'] . ' ' . $presence['surveillant_prenom']) : 
                            'Système automatique'; ?>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="mb-3">
                    <label class="form-label text-muted">Code QR Scanné</label>
                    <div class="form-control bg-light">
                        <i class="fas fa-qrcode me-2"></i>
                        <?php echo $presence['qr_code_scanne'] ? 'Oui' : 'Non'; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if($presence['motif_absence']): ?>
        <div class="mb-3">
            <label class="form-label text-muted">Motif d'absence</label>
            <div class="form-control bg-light">
                <i class="fas fa-comment me-2"></i>
                <?php echo htmlspecialchars($presence['motif_absence']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if($presence['observations']): ?>
        <div class="mb-3">
            <label class="form-label text-muted">Observations</label>
            <div class="form-control bg-light">
                <i class="fas fa-sticky-note me-2"></i>
                <?php echo htmlspecialchars($presence['observations']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="mb-3">
            <label class="form-label text-muted">Informations techniques</label>
            <div class="form-control bg-light">
                <small>
                    <i class="fas fa-info-circle me-1"></i>
                    IP: <?php echo $presence['ip_address'] ?? 'N/A'; ?> | 
                    Créé le: <?php echo formatDateFr($presence['date_creation']); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<div class="text-end mt-3">
    <button class="btn btn-primary" onclick="editPresence(<?php echo $presence['id']; ?>)">
        <i class="fas fa-edit me-1"></i> Modifier
    </button>
    <button class="btn btn-secondary" data-bs-dismiss="modal">
        Fermer
    </button>
</div>