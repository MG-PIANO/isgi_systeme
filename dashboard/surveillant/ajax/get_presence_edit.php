<?php
// dashboard/surveillant/ajax/get_presence_edit.php
require_once '../../../config/database.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    exit('Accès non autorisé');
}

$db = Database::getInstance()->getConnection();
$presence_id = $_GET['id'] ?? 0;

// Récupérer la présence
$query = "SELECT * FROM presences WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->execute([':id' => $presence_id]);
$presence = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$presence) {
    echo '<div class="alert alert-danger">Présence non trouvée</div>';
    exit();
}

// Récupérer les étudiants pour le select
$query = "SELECT id, matricule, nom, prenom FROM etudiants WHERE site_id = :site_id AND statut = 'actif' ORDER BY nom, prenom";
$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $_SESSION['site_id']]);
$etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les matières
$query = "SELECT id, nom FROM matieres WHERE site_id = :site_id ORDER BY nom";
$stmt = $db->prepare($query);
$stmt->execute([':site_id' => $_SESSION['site_id']]);
$matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<form id="editPresenceForm">
    <input type="hidden" name="id" value="<?php echo $presence['id']; ?>">
    
    <div class="mb-3">
        <label class="form-label">Étudiant <span class="text-danger">*</span></label>
        <select class="form-select" name="etudiant_id" required>
            <option value="">Sélectionner un étudiant</option>
            <?php foreach($etudiants as $etudiant): ?>
            <option value="<?php echo $etudiant['id']; ?>" 
                <?php echo $presence['etudiant_id'] == $etudiant['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['nom'] . ' ' . $etudiant['prenom']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label class="form-label">Type de présence <span class="text-danger">*</span></label>
                <select class="form-select" name="type_presence" required>
                    <option value="entree_ecole" <?php echo $presence['type_presence'] == 'entree_ecole' ? 'selected' : ''; ?>>Entrée École</option>
                    <option value="sortie_ecole" <?php echo $presence['type_presence'] == 'sortie_ecole' ? 'selected' : ''; ?>>Sortie École</option>
                    <option value="entree_classe" <?php echo $presence['type_presence'] == 'entree_classe' ? 'selected' : ''; ?>>Entrée Classe</option>
                    <option value="sortie_classe" <?php echo $presence['type_presence'] == 'sortie_classe' ? 'selected' : ''; ?>>Sortie Classe</option>
                </select>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <label class="form-label">Statut <span class="text-danger">*</span></label>
                <select class="form-select" name="statut" required>
                    <option value="present" <?php echo $presence['statut'] == 'present' ? 'selected' : ''; ?>>Présent</option>
                    <option value="absent" <?php echo $presence['statut'] == 'absent' ? 'selected' : ''; ?>>Absent</option>
                    <option value="retard" <?php echo $presence['statut'] == 'retard' ? 'selected' : ''; ?>>En retard</option>
                    <option value="justifie" <?php echo $presence['statut'] == 'justifie' ? 'selected' : ''; ?>>Justifié</option>
                </select>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="date" 
                       value="<?php echo date('Y-m-d', strtotime($presence['date_heure'])); ?>" required>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <label class="form-label">Heure <span class="text-danger">*</span></label>
                <input type="time" class="form-control" name="heure" 
                       value="<?php echo date('H:i', strtotime($presence['date_heure'])); ?>" required>
            </div>
        </div>
    </div>
    
    <div class="mb-3">
        <label class="form-label">Matière</label>
        <select class="form-select" name="matiere_id">
            <option value="">Sélectionner une matière</option>
            <?php foreach($matieres as $matiere): ?>
            <option value="<?php echo $matiere['id']; ?>" 
                <?php echo $presence['matiere_id'] == $matiere['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($matiere['nom']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="mb-3">
        <label class="form-label">Salle</label>
        <input type="text" class="form-control" name="salle" 
               value="<?php echo htmlspecialchars($presence['salle'] ?? ''); ?>"
               placeholder="Ex: Salle A, Amphithéâtre...">
    </div>
    
    <div class="mb-3">
        <label class="form-label">Motif d'absence (si absent)</label>
        <textarea class="form-control" name="motif_absence" rows="2"
                  placeholder="Raison de l'absence..."><?php echo htmlspecialchars($presence['motif_absence'] ?? ''); ?></textarea>
    </div>
    
    <div class="mb-3">
        <label class="form-label">Observations</label>
        <textarea class="form-control" name="observations" rows="2"
                  placeholder="Notes supplémentaires..."><?php echo htmlspecialchars($presence['observations'] ?? ''); ?></textarea>
    </div>
    
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="notifier_parent" id="notifierParent">
        <label class="form-check-label" for="notifierParent">
            Notifier le parent/tuteur (en cas d'absence ou retard)
        </label>
    </div>
    
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        Les modifications seront enregistrées avec votre nom comme surveillant.
    </div>
    
    <div class="text-end">
        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> Enregistrer
        </button>
    </div>
</form>

<script>
document.getElementById('editPresenceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('ajax/update_presence.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Succès !', data.message, 'success')
                .then(() => {
                    location.reload();
                });
        } else {
            Swal.fire('Erreur !', data.message, 'error');
        }
    })
    .catch(error => {
        Swal.fire('Erreur', 'Une erreur est survenue', 'error');
        console.error('Erreur:', error);
    });
});
</script>