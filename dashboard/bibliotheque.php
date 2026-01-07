<?php
// dashboard/bibliotheque.php
define('ROOT_PATH', dirname(dirname(__FILE__)));
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

include_once ROOT_PATH . '/config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = "Bibliothèque en ligne";

$action = $_GET['action'] ?? 'list';

// Fonction pour convertir le texte
function convertToTimesNewRoman($text) {
    // Cette fonction peut être implémentée avec une API de conversion
    // ou avec une bibliothèque PHP pour le traitement de texte
    return $text; // Pour l'instant, retourne le texte tel quel
}

// Fonction pour formater la taille
function formatTaille($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
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
    <!-- Include TinyMCE for rich text editor -->
    <script src="https://cdn.tiny.cloud/1/YOUR_API_KEY/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <style>
    .book-card {
        transition: transform 0.3s;
        border: 1px solid #dee2e6;
        height: 100%;
    }
    .book-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .book-cover {
        height: 200px;
        object-fit: cover;
        width: 100%;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3rem;
        color: #6c757d;
    }
    .badge-category {
        font-size: 0.75em;
    }
    .reader-view {
        font-family: 'Times New Roman', serif;
        line-height: 1.5;
        text-align: left;
        direction: ltr;
        background: white;
        padding: 20px;
        min-height: 500px;
    }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="bibliotheque.php">
                                <i class="fas fa-book"></i> Catalogue
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="bibliotheque.php?action=ajouter">
                                <i class="fas fa-plus-circle"></i> Ajouter un livre
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="bibliotheque.php?action=editeur">
                                <i class="fas fa-edit"></i> Éditeur de livrets
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="bibliotheque.php?action=emprunts">
                                <i class="fas fa-exchange-alt"></i> Emprunts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="bibliotheque.php?action=favoris">
                                <i class="fas fa-heart"></i> Favoris
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="bibliotheque.php?action=statistiques">
                                <i class="fas fa-chart-bar"></i> Statistiques
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="fas fa-book-reader"></i> Bibliothèque en ligne
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-primary" onclick="location.href='bibliotheque.php?action=ajouter'">
                            <i class="fas fa-plus"></i> Ajouter un livre
                        </button>
                    </div>
                </div>

                <?php if($action == 'list'): ?>
                <!-- Catalogue des livres -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control" placeholder="Rechercher un livre..." id="searchBook">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="filterCategory">
                            <option value="">Toutes les catégories</option>
                            <?php
                            $categories = $db->query("SELECT * FROM bibliotheque_categories ORDER BY nom")->fetchAll();
                            foreach($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row" id="booksList">
                    <?php
                   // Utilisez:
$query = "SELECT l.*, 
                 COALESCE(c.nom, l.categorie) as categorie_nom, 
                 c.couleur 
          FROM livres l 
          LEFT JOIN livre_categories c ON l.categorie_id = c.id 
          WHERE l.site_id = ? 
          ORDER BY l.date_ajout DESC";

$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['site_id']]);
$livres = $stmt->fetchAll();
                    ?>

                    <?php foreach($livres as $livre): ?>
                    <div class="col-md-4 col-lg-3 mb-4">
                        <div class="card book-card">
                            <div class="book-cover">
                                <?php if($livre['couverture']): ?>
                                <img src="<?php echo $livre['couverture']; ?>" alt="Couverture" class="img-fluid">
                                <?php else: ?>
                                <i class="fas fa-book"></i>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title mb-0"><?php echo htmlspecialchars($livre['titre']); ?></h6>
                                    <?php if($livre['categorie_nom']): ?>
                                    <span class="badge badge-category" style="background-color: <?php echo $livre['couleur']; ?>">
                                        <?php echo htmlspecialchars($livre['categorie_nom']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <p class="card-text text-muted small">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($livre['auteur']); ?>
                                </p>
                                
                                <p class="card-text small">
                                    <i class="fas fa-file-pdf"></i> <?php echo formatTaille($livre['taille_fichier']); ?>
                                    <br>
                                    <i class="fas fa-eye"></i> <?php echo $livre['nombre_vues']; ?> vues
                                </p>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <a href="bibliotheque.php?action=view&id=<?php echo $livre['id']; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye"></i> Lire
                                    </a>
                                    <a href="<?php echo $livre['fichier_pdf']; ?>" class="btn btn-outline-success btn-sm" download>
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if($action == 'ajouter'): ?>
                <!-- Formulaire d'ajout de livre -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Ajouter un nouveau livre</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="bibliotheque_action.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add_book">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Titre *</label>
                                        <input type="text" class="form-control" name="titre" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Auteur *</label>
                                        <input type="text" class="form-control" name="auteur" required>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">ISBN</label>
                                        <input type="text" class="form-control" name="isbn">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Éditeur</label>
                                        <input type="text" class="form-control" name="editeur">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Année de publication</label>
                                        <input type="number" class="form-control" name="annee_publication" min="1900" max="<?php echo date('Y'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Catégorie</label>
                                        <select class="form-select" name="categorie_id">
                                            <option value="">Sélectionner une catégorie</option>
                                            <?php foreach($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>">
                                                <?php echo htmlspecialchars($cat['nom']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Résumé</label>
                                <textarea class="form-control" name="resume" rows="4"></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Fichier PDF *</label>
                                        <input type="file" class="form-control" name="fichier_pdf" accept=".pdf" required>
                                        <small class="text-muted">Taille maximale: 50MB</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Image de couverture</label>
                                        <input type="file" class="form-control" name="couverture" accept="image/*">
                                        <small class="text-muted">Format: JPG, PNG. Taille recommandée: 400x600px</small>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Ajouter le livre
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if($action == 'editeur'): ?>
                <!-- Éditeur de livrets -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-edit"></i> Éditeur de livrets</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <button class="btn btn-primary w-100" onclick="newDocument()">
                                    <i class="fas fa-file"></i> Nouveau document
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-success w-100" onclick="saveDocument()">
                                    <i class="fas fa-save"></i> Sauvegarder
                                </button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-info w-100" onclick="publishDocument()">
                                    <i class="fas fa-share"></i> Publier
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <input type="text" class="form-control" id="documentTitle" placeholder="Titre du document">
                        </div>

                        <!-- Barre d'outils d'édition -->
                        <div class="bg-light p-2 mb-3 border rounded">
                            <div class="btn-group" role="group">
                                <button class="btn btn-outline-secondary" onclick="execCommand('bold')">
                                    <i class="fas fa-bold"></i>
                                </button>
                                <button class="btn btn-outline-secondary" onclick="execCommand('italic')">
                                    <i class="fas fa-italic"></i>
                                </button>
                                <button class="btn btn-outline-secondary" onclick="execCommand('underline')">
                                    <i class="fas fa-underline"></i>
                                </button>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-font"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" onclick="changeFont('Arial')">Arial</a></li>
                                        <li><a class="dropdown-item" onclick="changeFont('Times New Roman')">Times New Roman</a></li>
                                        <li><a class="dropdown-item" onclick="changeFont('Verdana')">Verdana</a></li>
                                        <li><a class="dropdown-item" onclick="changeFont('Courier New')">Courier New</a></li>
                                    </ul>
                                </div>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-palette"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" onclick="changeColor('#000000')">Noir</a></li>
                                        <li><a class="dropdown-item" onclick="changeColor('#e74c3c')">Rouge</a></li>
                                        <li><a class="dropdown-item" onclick="changeColor('#3498db')">Bleu</a></li>
                                        <li><a class="dropdown-item" onclick="changeColor('#2ecc71')">Vert</a></li>
                                        <li><a class="dropdown-item" onclick="changeColor('#f39c12')">Orange</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Zone d'édition -->
                        <div id="editor" class="reader-view" contenteditable="true" style="min-height: 500px;">
                            <p>Commencez à écrire votre document ici...</p>
                        </div>

                        <!-- Compteur de pages -->
                        <div class="mt-3 text-end">
                            <small class="text-muted">Nombre de pages: <span id="pageCount">0</span></small>
                        </div>
                    </div>
                </div>

                <script>
                let autoSaveInterval;
                let documentId = null;
                
                // Initialiser l'auto-sauvegarde
                function startAutoSave() {
                    autoSaveInterval = setInterval(function() {
                        if (document.getElementById('editor').textContent.trim().length > 0) {
                            saveDocument(true);
                        }
                    }, 300000); // 5 minutes
                }
                
                // Sauvegarder le document
                function saveDocument(auto = false) {
                    const content = document.getElementById('editor').innerHTML;
                    const title = document.getElementById('documentTitle').value;
                    const pages = calculatePages();
                    
                    // Envoyer la sauvegarde via AJAX
                    fetch('bibliotheque_action.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=save_document&document_id=${documentId}&title=${encodeURIComponent(title)}&content=${encodeURIComponent(content)}&pages=${pages}&auto=${auto ? 1 : 0}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (!auto) {
                                alert('Document sauvegardé avec succès!');
                            }
                            if (data.document_id && !documentId) {
                                documentId = data.document_id;
                            }
                        }
                    });
                }
                
                // Publier le document
                function publishDocument() {
                    const content = document.getElementById('editor').innerHTML;
                    const title = document.getElementById('documentTitle').value;
                    const pages = calculatePages();
                    
                    if (pages < 30) {
                        alert('Le document doit contenir au minimum 30 pages pour être publié.');
                        return;
                    }
                    
                    if (confirm('Êtes-vous sûr de vouloir publier ce document?')) {
                        fetch('bibliotheque_action.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=publish_document&document_id=${documentId}&title=${encodeURIComponent(title)}&content=${encodeURIComponent(content)}&pages=${pages}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert('Document publié avec succès!');
                            }
                        });
                    }
                }
                
                // Calculer le nombre de pages
                function calculatePages() {
                    const content = document.getElementById('editor').textContent;
                    const words = content.split(/\s+/).length;
                    // Estimation: 300 mots par page
                    return Math.ceil(words / 300);
                }
                
                // Mettre à jour le compteur de pages
                function updatePageCount() {
                    document.getElementById('pageCount').textContent = calculatePages();
                }
                
                // Exécuter des commandes d'édition
                function execCommand(command) {
                    document.execCommand(command, false, null);
                    updatePageCount();
                }
                
                function changeFont(font) {
                    document.execCommand('fontName', false, font);
                }
                
                function changeColor(color) {
                    document.execCommand('foreColor', false, color);
                }
                
                // Démarrer l'auto-sauvegarde
                startAutoSave();
                
                // Mettre à jour le compteur de pages en temps réel
                document.getElementById('editor').addEventListener('input', updatePageCount);
                </script>
                <?php endif; ?>

                <?php if($action == 'view'): ?>
                <!-- Lecture d'un livre -->
                <?php
                $id = $_GET['id'];
                $query = "SELECT b.*, c.nom as categorie_nom 
                          FROM bibliotheque_livres b 
                          LEFT JOIN bibliotheque_categories c ON b.categorie_id = c.id 
                          WHERE b.id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$id]);
                $livre = $stmt->fetch();
                
                // Incrémenter le nombre de vues
                $db->prepare("UPDATE bibliotheque_livres SET nombre_vues = nombre_vues + 1 WHERE id = ?")->execute([$id]);
                ?>

                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="book-cover mb-3">
                                    <?php if($livre['couverture']): ?>
                                    <img src="<?php echo $livre['couverture']; ?>" alt="Couverture" class="img-fluid">
                                    <?php else: ?>
                                    <i class="fas fa-book fa-4x"></i>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="list-group mb-3">
                                    <a href="<?php echo $livre['fichier_pdf']; ?>" class="list-group-item list-group-item-action" download>
                                        <i class="fas fa-download"></i> Télécharger PDF
                                    </a>
                                    <button class="list-group-item list-group-item-action" onclick="addToFavorites(<?php echo $livre['id']; ?>)">
                                        <i class="fas fa-heart"></i> Ajouter aux favoris
                                    </button>
                                </div>
                                
                                <div class="card">
                                    <div class="card-body">
                                        <h6>Informations</h6>
                                        <p><small><strong>Auteur:</strong> <?php echo htmlspecialchars($livre['auteur']); ?></small></p>
                                        <p><small><strong>Éditeur:</strong> <?php echo htmlspecialchars($livre['editeur']); ?></small></p>
                                        <p><small><strong>Année:</strong> <?php echo $livre['annee_publication']; ?></small></p>
                                        <p><small><strong>Pages:</strong> <?php echo $livre['nombre_pages']; ?></small></p>
                                        <p><small><strong>Vues:</strong> <?php echo $livre['nombre_vues']; ?></small></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-9">
                                <div class="reader-view">
                                    <h2><?php echo htmlspecialchars($livre['titre']); ?></h2>
                                    <p class="text-muted">par <?php echo htmlspecialchars($livre['auteur']); ?></p>
                                    
                                    <hr>
                                    
                                    <h4>Résumé</h4>
                                    <p><?php echo nl2br(htmlspecialchars($livre['resume'])); ?></p>
                                    
                                    <hr>
                                    
                                    <!-- Afficher le PDF en ligne -->
                                    <embed src="<?php echo $livre['fichier_pdf']; ?>" type="application/pdf" width="100%" height="600px">
                                </div>
                                
                                <!-- Commentaires -->
                                <div class="mt-4">
                                    <h5>Commentaires</h5>
                                    <form class="mb-3">
                                        <div class="mb-2">
                                            <textarea class="form-control" rows="3" placeholder="Ajouter un commentaire..."></textarea>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <div class="rating">
                                                <?php for($i = 1; $i <= 5; $i++): ?>
                                                <i class="far fa-star text-warning" data-rating="<?php echo $i; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm">Envoyer</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Filtrage des livres
    document.getElementById('searchBook').addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const books = document.querySelectorAll('#booksList .col-md-4');
        
        books.forEach(book => {
            const title = book.querySelector('.card-title').textContent.toLowerCase();
            const author = book.querySelector('.card-text').textContent.toLowerCase();
            
            if (title.includes(searchTerm) || author.includes(searchTerm)) {
                book.style.display = 'block';
            } else {
                book.style.display = 'none';
            }
        });
    });
    
    // Système de notation
    document.querySelectorAll('.rating i').forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.dataset.rating;
            const stars = this.parentElement.querySelectorAll('i');
            
            stars.forEach((s, index) => {
                if (index < rating) {
                    s.classList.remove('far');
                    s.classList.add('fas');
                } else {
                    s.classList.remove('fas');
                    s.classList.add('far');
                }
            });
        });
    });
    </script>
</body>
</html>