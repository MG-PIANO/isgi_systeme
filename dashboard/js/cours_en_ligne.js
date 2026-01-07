// dashboard/js/cours_en_ligne.js
document.addEventListener('DOMContentLoaded', function() {
    // Gestion du formulaire principal
    const coursForm = document.getElementById('coursForm');
    if (coursForm) {
        coursForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleFormSubmit(this);
        });
    }
    
    // Gestion des boutons de suppression
    document.querySelectorAll('.btn-delete-cours').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const coursId = this.getAttribute('data-id');
            if (coursId && confirm('Êtes-vous sûr de vouloir supprimer ce cours ?')) {
                deleteCours(coursId);
            }
        });
    });
});

// Fonction pour soumettre le formulaire
async function handleFormSubmit(form) {
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    try {
        // Désactiver le bouton pendant le traitement
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement...';
        
        // Créer FormData
        const formData = new FormData(form);
        
        // Envoyer la requête
        const response = await fetch('cours_en_ligne_action.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Afficher un message temporaire
            showNotification('Succès', 'Opération effectuée avec succès !', 'success');
            
            // Rediriger après 1.5 secondes
            setTimeout(() => {
                if (result.redirect) {
                    window.location.href = result.redirect;
                } else {
                    window.location.reload();
                }
            }, 1500);
        } else {
            showNotification('Erreur', result.message || 'Une erreur est survenue', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
        
    } catch (error) {
        showNotification('Erreur', 'Erreur de connexion au serveur', 'error');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        console.error('Erreur:', error);
    }
}

// Fonction pour supprimer un cours
async function deleteCours(coursId) {
    try {
        const response = await fetch(`cours_en_ligne_action.php?action=delete&id=${coursId}`);
        const result = await response.json();
        
        if (result.success) {
            showNotification('Succès', 'Cours supprimé avec succès', 'success');
            setTimeout(() => {
                if (result.redirect) {
                    window.location.href = result.redirect;
                } else {
                    window.location.reload();
                }
            }, 1500);
        } else {
            showNotification('Erreur', result.message, 'error');
        }
    } catch (error) {
        showNotification('Erreur', 'Erreur de connexion', 'error');
    }
}

// Fonction pour afficher des notifications
function showNotification(title, message, type = 'info') {
    // Si vous avez SweetAlert2
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: title,
            text: message,
            icon: type,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
    }
    // Sinon, utiliser les alertes natives
    else {
        alert(`${title}: ${message}`);
    }
}