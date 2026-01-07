<?php
// dashboard/admin_principal/bibliotheque.php

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

// Vérifier les permissions
if ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 2) {
    header('Location: dashboard.php');
    exit();
}

// Inclure la configuration
@include_once ROOT_PATH . '/config/database.php';

// Vérifier si la connexion à la base de données est disponible
if (!class_exists('Database')) {
    die("Erreur: Impossible de charger la configuration de la base de données.");
}

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "Gestion de la Bibliothèque";
    
    // Initialiser les variables
    $message = '';
    $error = '';
    $action = isset($_GET['action']) ? $_GET['action'] : 'list';
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    // Fonction pour uploader des fichiers
    function uploadFile($file, $type = 'pdf', $maxSize = 50000000) { // 50MB par défaut
        $uploadDir = ROOT_PATH . '/assets/uploads/';
        
        // Créer les dossiers si nécessaire
        if ($type == 'pdf') {
            $uploadDir .= 'livres/';
        } else {
            $uploadDir .= 'couvertures/';
        }
        
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        // Vérifier les erreurs d'upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Erreur lors du téléchargement du fichier.");
        }
        
        // Vérifier la taille
        if ($file['size'] > $maxSize) {
            throw new Exception("Le fichier est trop volumineux. Taille max: " . ($maxSize / 1000000) . "MB");
        }
        
        // Vérifier le type de fichier
        $allowedTypes = $type == 'pdf' ? ['application/pdf'] : ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception("Type de fichier non autorisé. Types autorisés: " . ($type == 'pdf' ? 'PDF' : 'Images'));
        }
        
        // Générer un nom de fichier unique
        $extension = $type == 'pdf' ? '.pdf' : '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', basename($file['name'], $extension)) . $extension;
        $filepath = $uploadDir . $filename;
        
        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception("Impossible de déplacer le fichier téléchargé.");
        }
        
        // Retourner le chemin relatif
        return 'assets/uploads/' . ($type == 'pdf' ? 'livres/' : 'couvertures/') . $filename;
    }
    
    // Actions CRUD
    switch ($action) {
        case 'add':
            $pageTitle = "Ajouter un Livre";
            
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                try {
                    // Upload du PDF
                    if (!isset($_FILES['fichier_pdf']) || $_FILES['fichier_pdf']['error'] == UPLOAD_ERR_NO_FILE) {
                        throw new Exception("Veuillez sélectionner un fichier PDF.");
                    }
                    $fichier_pdf = uploadFile($_FILES['fichier_pdf'], 'pdf');
                    
                    // Upload de la couverture (optionnel)
                    $couverture = null;
                    if (isset($_FILES['couverture']) && $_FILES['couverture']['error'] == UPLOAD_ERR_OK) {
                        $couverture = uploadFile($_FILES['couverture'], 'image', 5000000); // 5MB max pour les images
                    }
                    
                    // Insérer le livre dans la base de données
                    $stmt = $db->prepare("INSERT INTO bibliotheque_livres 
                        (isbn, titre, auteur, editeur, annee_publication, description, resume, 
                         fichier_pdf, couverture, taille_fichier, format_fichier, site_id, 
                         filiere_id, niveau_id, matiere_id, langue, nombre_pages, statut, ajoute_par) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $taille_fichier = filesize(ROOT_PATH . '/' . $fichier_pdf);
                    $format_fichier = 'pdf';
                    $statut = 'disponible';
                    
                    $stmt->execute([
                        $_POST['isbn'] ?? null,
                        $_POST['titre'],
                        $_POST['auteur'],
                        $_POST['editeur'] ?? null,
                        $_POST['annee_publication'] ?? null,
                        $_POST['description'] ?? null,
                        $_POST['resume'] ?? null,
                        $fichier_pdf,
                        $couverture,
                        $taille_fichier,
                        $format_fichier,
                        $_SESSION['site_id'] ?? 1,
                        $_POST['filiere_id'] ?? null,
                        $_POST['niveau_id'] ?? null,
                        $_POST['matiere_id'] ?? null,
                        $_POST['langue'] ?? 'Français',
                        $_POST['nombre_pages'] ?? 0,
                        $statut,
                        $_SESSION['user_id']
                    ]);
                    
                    $message = "Livre ajouté avec succès!";
                    $action = 'list'; // Rediriger vers la liste
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
            break;
            
        case 'edit':
            $pageTitle = "Modifier un Livre";
            
            // Récupérer le livre à modifier
            $stmt = $db->prepare("SELECT * FROM bibliotheque_livres WHERE id = ?");
            $stmt->execute([$id]);
            $livre = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$livre) {
                $error = "Livre non trouvé.";
                $action = 'list';
                break;
            }
            
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                try {
                    // Préparer les données
                    $fichier_pdf = $livre['fichier_pdf'];
                    $couverture = $livre['couverture'];
                    
                    // Upload du nouveau PDF si fourni
                    if (isset($_FILES['fichier_pdf']) && $_FILES['fichier_pdf']['error'] == UPLOAD_ERR_OK) {
                        // Supprimer l'ancien fichier
                        if ($livre['fichier_pdf'] && file_exists(ROOT_PATH . '/' . $livre['fichier_pdf'])) {
                            unlink(ROOT_PATH . '/' . $livre['fichier_pdf']);
                        }
                        $fichier_pdf = uploadFile($_FILES['fichier_pdf'], 'pdf');
                    }
                    
                    // Upload de la nouvelle couverture si fournie
                    if (isset($_FILES['couverture']) && $_FILES['couverture']['error'] == UPLOAD_ERR_OK) {
                        // Supprimer l'ancienne image
                        if ($livre['couverture'] && file_exists(ROOT_PATH . '/' . $livre['couverture'])) {
                            unlink(ROOT_PATH . '/' . $livre['couverture']);
                        }
                        $couverture = uploadFile($_FILES['couverture'], 'image', 5000000);
                    }
                    
                    // Mettre à jour le livre
                    $stmt = $db->prepare("UPDATE bibliotheque_livres SET 
                        isbn = ?, titre = ?, auteur = ?, editeur = ?, annee_publication = ?,
                        description = ?, resume = ?, fichier_pdf = ?, couverture = ?,
                        filiere_id = ?, niveau_id = ?, matiere_id = ?, langue = ?, nombre_pages = ?
                        WHERE id = ?");
                    
                    $stmt->execute([
                        $_POST['isbn'] ?? null,
                        $_POST['titre'],
                        $_POST['auteur'],
                        $_POST['editeur'] ?? null,
                        $_POST['annee_publication'] ?? null,
                        $_POST['description'] ?? null,
                        $_POST['resume'] ?? null,
                        $fichier_pdf,
                        $couverture,
                        $_POST['filiere_id'] ?? null,
                        $_POST['niveau_id'] ?? null,
                        $_POST['matiere_id'] ?? null,
                        $_POST['langue'] ?? 'Français',
                        $_POST['nombre_pages'] ?? 0,
                        $id
                    ]);
                    
                    $message = "Livre modifié avec succès!";
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
            break;
            
        case 'delete':
            // Récupérer le livre à supprimer
            $stmt = $db->prepare("SELECT * FROM bibliotheque_livres WHERE id = ?");
            $stmt->execute([$id]);
            $livre = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($livre) {
                // Supprimer les fichiers
                if ($livre['fichier_pdf'] && file_exists(ROOT_PATH . '/' . $livre['fichier_pdf'])) {
                    unlink(ROOT_PATH . '/' . $livre['fichier_pdf']);
                }
                if ($livre['couverture'] && file_exists(ROOT_PATH . '/' . $livre['couverture'])) {
                    unlink(ROOT_PATH . '/' . $livre['couverture']);
                }
                
                // Supprimer de la base de données
                $stmt = $db->prepare("DELETE FROM bibliotheque_livres WHERE id = ?");
                $stmt->execute([$id]);
                
                $message = "Livre supprimé avec succès!";
            } else {
                $error = "Livre non trouvé.";
            }
            
            $action = 'list';
            break;
            
        case 'view':
            $pageTitle = "Détails du Livre";
            
            // Récupérer le livre
            $stmt = $db->prepare("SELECT bl.*, s.nom as site_nom 
                FROM bibliotheque_livres bl 
                LEFT JOIN sites s ON bl.site_id = s.id 
                WHERE bl.id = ?");
            $stmt->execute([$id]);
            $livre = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$livre) {
                $error = "Livre non trouvé.";
                $action = 'list';
            }
            break;
            
        default: // 'list'
            // Récupérer tous les livres avec pagination
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = 20;
            $offset = ($page - 1) * $limit;
            
            // Compter le nombre total de livres
            $stmt = $db->query("SELECT COUNT(*) as total FROM bibliotheque_livres");
            $totalLivres = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            $totalPages = ceil($totalLivres / $limit);
            
            // Récupérer les livres pour la page actuelle
            $stmt = $db->prepare("SELECT bl.*, s.nom as site_nom 
                FROM bibliotheque_livres bl 
                LEFT JOIN sites s ON bl.site_id = s.id 
                ORDER BY bl.date_ajout DESC 
                LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $livres = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    // Récupérer les listes pour les formulaires
    $stmt = $db->query("SELECT id, nom FROM filieres ORDER BY nom");
    $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, libelle FROM niveaux ORDER BY ordre");
    $niveaux = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, nom FROM matieres ORDER BY nom");
    $matieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Erreur: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Bibliothèque ISGI</title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Style personnalisé -->
    <style>
    .book-card {
        transition: transform 0.3s, box-shadow 0.3s;
        height: 100%;
    }
    
    .book-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
    
    .book-cover {
        height: 250px;
        object-fit: cover;
        background: linear-gradient(45deg, #3498db, #2c3e50);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 48px;
    }
    
    .book-cover img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .book-info {
        padding: 15px;
    }
    
    .book-title {
        font-size: 1.1rem;
        font-weight: bold;
        margin-bottom: 5px;
        color: #2c3e50;
    }
    
    .book-author {
        color: #7f8c8d;
        font-size: 0.9rem;
        margin-bottom: 10px;
    }
    
    .book-meta {
        font-size: 0.8rem;
        color: #95a5a6;
    }
    
    .action-buttons {
        margin-top: 10px;
        display: flex;
        gap: 5px;
    }
    
    .stats-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .stats-icon {
        font-size: 2.5rem;
        margin-bottom: 10px;
    }
    
    .stats-value {
        font-size: 2rem;
        font-weight: bold;
    }
    
    .stats-label {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    /* Style pour l'éditeur de texte */
    .editor-toolbar {
        background: #f8f9fa;
        padding: 10px;
        border: 1px solid #dee2e6;
        border-bottom: none;
        border-radius: 5px 5px 0 0;
    }
    
    .editor-content {
        min-height: 400px;
        border: 1px solid #dee2e6;
        padding: 15px;
        font-family: "Times New Roman", Times, serif;
        font-size: 12pt;
        line-height: 1.5;
        direction: ltr;
        text-align: left;
    }
    
    .editor-content:focus {
        outline: none;
    }
    
    .page-counter {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #2c3e50;
        color: white;
        padding: 10px 20px;
        border-radius: 20px;
        font-size: 0.9rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    
    .auto-save {
        position: fixed;
        bottom: 20px;
        left: 20px;
        background: #27ae60;
        color: white;
        padding: 10px 20px;
        border-radius: 20px;
        font-size: 0.9rem;
        display: none;
    }
    
    /* Style pour la prévisualisation PDF */
    .pdf-preview {
        width: 100%;
        height: 600px;
        border: 1px solid #dee2e6;
        border-radius: 5px;
    }
    
    .file-info {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
        margin-top: 10px;
    }
    </style>
</head>
<body>
    <!-- Inclure la sidebar du dashboard -->
    <?php include 'dashboard.php'; ?>
    
    <div class="main-content">
        <!-- En-tête -->
        <div class="content-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-book me-2"></i>
                        <?php echo htmlspecialchars($pageTitle); ?>
                    </h2>
                    <p class="text-muted mb-0">Gestion de la bibliothèque numérique ISGI</p>
                </div>
                <div class="btn-group">
                    <a href="bibliotheque.php?action=list" class="btn btn-outline-primary">
                        <i class="fas fa-list"></i> Liste
                    </a>
                    <a href="bibliotheque.php?action=add" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Ajouter un Livre
                    </a>
                    <a href="editeur_document.php" class="btn btn-success">
                        <i class="fas fa-edit"></i> Éditeur de Document
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Messages d'alerte -->
        <?php if($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Contenu principal selon l'action -->
        <?php if($action == 'list'): ?>
        <!-- Section Statistiques -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stats-value"><?php echo $totalLivres; ?></div>
                    <div class="stats-label">Livres Totaux</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stats-icon">
                        <i class="fas fa-download"></i>
                    </div>
                    <div class="stats-value">
                        <?php 
                        $stmt = $db->query("SELECT COALESCE(SUM(nombre_telechargements), 0) as total FROM bibliotheque_livres");
                        echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                        ?>
                    </div>
                    <div class="stats-label">Téléchargements</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stats-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stats-value">
                        <?php 
                        $stmt = $db->query("SELECT COALESCE(SUM(nombre_vues), 0) as total FROM bibliotheque_livres");
                        echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                        ?>
                    </div>
                    <div class="stats-label">Vues Totales</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="stats-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stats-value">
                        <?php 
                        $stmt = $db->query("SELECT COUNT(*) as total FROM bibliotheque_documents");
                        echo $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                        ?>
                    </div>
                    <div class="stats-label">Documents Créés</div>
                </div>
            </div>
        </div>
        
        <!-- Barre de recherche et filtres -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="action" value="list">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control" placeholder="Rechercher un livre..." 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="filiere" class="form-select">
                            <option value="">Toutes les filières</option>
                            <?php foreach($filieres as $filiere): ?>
                            <option value="<?php echo $filiere['id']; ?>" 
                                <?php echo (isset($_GET['filiere']) && $_GET['filiere'] == $filiere['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($filiere['nom']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="statut" class="form-select">
                            <option value="">Tous les statuts</option>
                            <option value="disponible" <?php echo (isset($_GET['statut']) && $_GET['statut'] == 'disponible') ? 'selected' : ''; ?>>Disponible</option>
                            <option value="emprunte" <?php echo (isset($_GET['statut']) && $_GET['statut'] == 'emprunte') ? 'selected' : ''; ?>>Emprunté</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Liste des livres -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Catalogue des Livres</h5>
                <span class="badge bg-primary"><?php echo $totalLivres; ?> livres</span>
            </div>
            <div class="card-body">
                <?php if(empty($livres)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                    <h4>Aucun livre disponible</h4>
                    <p class="text-muted">Commencez par ajouter des livres à la bibliothèque.</p>
                    <a href="bibliotheque.php?action=add" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Ajouter le premier livre
                    </a>
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach($livres as $livre): ?>
                    <div class="col-md-3 mb-4">
                        <div class="card book-card">
                            <div class="book-cover">
                                <?php if($livre['couverture']): ?>
                                <img src="<?php echo htmlspecialchars($livre['couverture']); ?>" 
                                     alt="<?php echo htmlspecialchars($livre['titre']); ?>">
                                <?php else: ?>
                                <i class="fas fa-book"></i>
                                <?php endif; ?>
                            </div>
                            <div class="book-info">
                                <div class="book-title" title="<?php echo htmlspecialchars($livre['titre']); ?>">
                                    <?php echo mb_strimwidth(htmlspecialchars($livre['titre']), 0, 40, '...'); ?>
                                </div>
                                <div class="book-author">
                                    <i class="fas fa-user"></i> 
                                    <?php echo mb_strimwidth(htmlspecialchars($livre['auteur']), 0, 30, '...'); ?>
                                </div>
                                <div class="book-meta">
                                    <div><i class="fas fa-calendar"></i> <?php echo $livre['annee_publication'] ?? 'N/A'; ?></div>
                                    <div><i class="fas fa-file"></i> <?php echo number_format($livre['taille_fichier'] / 1024, 0); ?> KB</div>
                                </div>
                                <div class="action-buttons">
                                    <a href="bibliotheque.php?action=view&id=<?php echo $livre['id']; ?>" 
                                       class="btn btn-sm btn-outline-info" title="Voir">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="bibliotheque.php?action=edit&id=<?php echo $livre['id']; ?>" 
                                       class="btn btn-sm btn-outline-warning" title="Modifier">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="bibliotheque.php?action=delete&id=<?php echo $livre['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce livre ?')" title="Supprimer">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars($livre['fichier_pdf']); ?>" 
                                       target="_blank" class="btn btn-sm btn-outline-success" title="Télécharger">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if($totalPages > 1): ?>
                <nav aria-label="Pagination">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?action=list&page=<?php echo $page - 1; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        
                        <?php for($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?action=list&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?php echo $page == $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?action=list&page=<?php echo $page + 1; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php elseif($action == 'add' || $action == 'edit'): ?>
        <!-- Formulaire d'ajout/modification -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-book me-2"></i>
                    <?php echo $action == 'add' ? 'Ajouter un Nouveau Livre' : 'Modifier le Livre'; ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="bookForm">
                    <div class="row">
                        <div class="col-md-8">
                            <!-- Informations de base -->
                            <div class="mb-3">
                                <label for="titre" class="form-label">Titre du Livre *</label>
                                <input type="text" class="form-control" id="titre" name="titre" required
                                       value="<?php echo isset($livre) ? htmlspecialchars($livre['titre']) : ''; ?>">
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="auteur" class="form-label">Auteur *</label>
                                    <input type="text" class="form-control" id="auteur" name="auteur" required
                                           value="<?php echo isset($livre) ? htmlspecialchars($livre['auteur']) : ''; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="isbn" class="form-label">ISBN</label>
                                    <input type="text" class="form-control" id="isbn" name="isbn"
                                           value="<?php echo isset($livre) ? htmlspecialchars($livre['isbn']) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="editeur" class="form-label">Éditeur</label>
                                    <input type="text" class="form-control" id="editeur" name="editeur"
                                           value="<?php echo isset($livre) ? htmlspecialchars($livre['editeur']) : ''; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="annee_publication" class="form-label">Année de Publication</label>
                                    <input type="number" class="form-control" id="annee_publication" name="annee_publication" 
                                           min="1900" max="<?php echo date('Y'); ?>"
                                           value="<?php echo isset($livre) ? htmlspecialchars($livre['annee_publication']) : ''; ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($livre) ? htmlspecialchars($livre['description']) : ''; ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="resume" class="form-label">Résumé</label>
                                <textarea class="form-control" id="resume" name="resume" rows="4"><?php echo isset($livre) ? htmlspecialchars($livre['resume']) : ''; ?></textarea>
                            </div>
                            
                            <!-- Fichiers -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="fichier_pdf" class="form-label">
                                        Fichier PDF * <?php echo $action == 'edit' ? '(Laisser vide pour garder le fichier actuel)' : ''; ?>
                                    </label>
                                    <input type="file" class="form-control" id="fichier_pdf" name="fichier_pdf" 
                                           accept=".pdf,application/pdf" <?php echo $action == 'add' ? 'required' : ''; ?>>
                                    <?php if($action == 'edit' && $livre['fichier_pdf']): ?>
                                    <div class="mt-2">
                                        <small>Fichier actuel: 
                                            <a href="<?php echo htmlspecialchars($livre['fichier_pdf']); ?>" target="_blank">
                                                <i class="fas fa-file-pdf"></i> Voir le PDF
                                            </a>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label for="couverture" class="form-label">
                                        Image de Couverture
                                    </label>
                                    <input type="file" class="form-control" id="couverture" name="couverture" 
                                           accept="image/*">
                                    <?php if($action == 'edit' && $livre['couverture']): ?>
                                    <div class="mt-2">
                                        <small>Image actuelle: 
                                            <a href="<?php echo htmlspecialchars($livre['couverture']); ?>" target="_blank">
                                                <i class="fas fa-image"></i> Voir l'image
                                            </a>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Classification -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label for="filiere_id" class="form-label">Filière</label>
                                    <select class="form-select" id="filiere_id" name="filiere_id">
                                        <option value="">Sélectionner une filière</option>
                                        <?php foreach($filieres as $filiere): ?>
                                        <option value="<?php echo $filiere['id']; ?>"
                                            <?php echo (isset($livre) && $livre['filiere_id'] == $filiere['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($filiere['nom']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="niveau_id" class="form-label">Niveau</label>
                                    <select class="form-select" id="niveau_id" name="niveau_id">
                                        <option value="">Sélectionner un niveau</option>
                                        <?php foreach($niveaux as $niveau): ?>
                                        <option value="<?php echo $niveau['id']; ?>"
                                            <?php echo (isset($livre) && $livre['niveau_id'] == $niveau['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($niveau['libelle']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="matiere_id" class="form-label">Matière</label>
                                    <select class="form-select" id="matiere_id" name="matiere_id">
                                        <option value="">Sélectionner une matière</option>
                                        <?php foreach($matieres as $matiere): ?>
                                        <option value="<?php echo $matiere['id']; ?>"
                                            <?php echo (isset($livre) && $livre['matiere_id'] == $matiere['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($matiere['nom']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="langue" class="form-label">Langue</label>
                                    <select class="form-select" id="langue" name="langue">
                                        <option value="Français" <?php echo (isset($livre) && $livre['langue'] == 'Français') ? 'selected' : ''; ?>>Français</option>
                                        <option value="Anglais" <?php echo (isset($livre) && $livre['langue'] == 'Anglais') ? 'selected' : ''; ?>>Anglais</option>
                                        <option value="Espagnol" <?php echo (isset($livre) && $livre['langue'] == 'Espagnol') ? 'selected' : ''; ?>>Espagnol</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="nombre_pages" class="form-label">Nombre de Pages</label>
                                    <input type="number" class="form-control" id="nombre_pages" name="nombre_pages" min="0"
                                           value="<?php echo isset($livre) ? htmlspecialchars($livre['nombre_pages']) : '0'; ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <!-- Prévisualisation et informations -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><i class="fas fa-info-circle"></i> Informations</h6>
                                </div>
                                <div class="card-body">
                                    <div id="preview-cover" class="mb-3 text-center">
                                        <?php if($action == 'edit' && $livre['couverture']): ?>
                                        <img src="<?php echo htmlspecialchars($livre['couverture']); ?>" 
                                             alt="Couverture" class="img-fluid rounded" style="max-height: 200px;">
                                        <?php else: ?>
                                        <div class="bg-light rounded p-5 text-center">
                                            <i class="fas fa-book fa-3x text-muted"></i>
                                            <p class="mt-2 mb-0">Aperçu de la couverture</p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Statut</label>
                                        <div class="form-control bg-light">
                                            <span class="badge bg-success">Disponible</span>
                                        </div>
                                    </div>
                                    
                                    <?php if($action == 'edit'): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Date d'ajout</label>
                                        <div class="form-control bg-light">
                                            <?php echo date('d/m/Y H:i', strtotime($livre['date_ajout'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Statistiques</label>
                                        <div class="small">
                                            <div><i class="fas fa-eye"></i> Vues: <?php echo $livre['nombre_vues'] ?? 0; ?></div>
                                            <div><i class="fas fa-download"></i> Téléchargements: <?php echo $livre['nombre_telechargements'] ?? 0; ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="alert alert-info">
                                        <small>
                                            <i class="fas fa-lightbulb"></i> 
                                            Le PDF sera automatiquement converti en Times New Roman avec un espacement de 1.5 lors de l'affichage.
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> 
                            <?php echo $action == 'add' ? 'Ajouter le Livre' : 'Mettre à jour'; ?>
                        </button>
                        <a href="bibliotheque.php?action=list" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Annuler
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Script pour la prévisualisation de l'image -->
        <script>
        document.getElementById('couverture').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('preview-cover').innerHTML = 
                        '<img src="' + e.target.result + '" alt="Couverture" class="img-fluid rounded" style="max-height: 200px;">';
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });
        </script>
        
        <?php elseif($action == 'view' && isset($livre)): ?>
        <!-- Vue détaillée du livre -->
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body text-center">
                        <?php if($livre['couverture']): ?>
                        <img src="<?php echo htmlspecialchars($livre['couverture']); ?>" 
                             alt="<?php echo htmlspecialchars($livre['titre']); ?>" 
                             class="img-fluid rounded mb-3" style="max-height: 300px;">
                        <?php else: ?>
                        <div class="bg-light rounded p-5 mb-3">
                            <i class="fas fa-book fa-5x text-muted"></i>
                        </div>
                        <?php endif; ?>
                        
                        <h4><?php echo htmlspecialchars($livre['titre']); ?></h4>
                        <p class="text-muted"><?php echo htmlspecialchars($livre['auteur']); ?></p>
                        
                        <div class="d-grid gap-2">
                            <a href="<?php echo htmlspecialchars($livre['fichier_pdf']); ?>" 
                               target="_blank" class="btn btn-primary">
                                <i class="fas fa-download"></i> Télécharger le PDF
                            </a>
                            <a href="bibliotheque.php?action=edit&id=<?php echo $livre['id']; ?>" 
                               class="btn btn-warning">
                                <i class="fas fa-edit"></i> Modifier
                            </a>
                            <a href="bibliotheque.php?action=list" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Retour
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Informations du livre -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-info-circle"></i> Détails</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th>ISBN:</th>
                                <td><?php echo htmlspecialchars($livre['isbn'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Éditeur:</th>
                                <td><?php echo htmlspecialchars($livre['editeur'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Année:</th>
                                <td><?php echo htmlspecialchars($livre['annee_publication'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Langue:</th>
                                <td><?php echo htmlspecialchars($livre['langue']); ?></td>
                            </tr>
                            <tr>
                                <th>Pages:</th>
                                <td><?php echo htmlspecialchars($livre['nombre_pages']); ?></td>
                            </tr>
                            <tr>
                                <th>Statut:</th>
                                <td>
                                    <span class="badge bg-<?php echo $livre['statut'] == 'disponible' ? 'success' : 'warning'; ?>">
                                        <?php echo $livre['statut'] == 'disponible' ? 'Disponible' : 'Emprunté'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Taille:</th>
                                <td><?php echo number_format($livre['taille_fichier'] / 1024, 2); ?> KB</td>
                            </tr>
                            <tr>
                                <th>Site:</th>
                                <td><?php echo htmlspecialchars($livre['site_nom']); ?></td>
                            </tr>
                            <tr>
                                <th>Ajouté le:</th>
                                <td><?php echo date('d/m/Y H:i', strtotime($livre['date_ajout'])); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <!-- Description et résumé -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-align-left"></i> Description</h6>
                    </div>
                    <div class="card-body">
                        <?php if($livre['description']): ?>
                        <p><?php echo nl2br(htmlspecialchars($livre['description'])); ?></p>
                        <?php else: ?>
                        <p class="text-muted fst-italic">Aucune description fournie.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Résumé -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-file-alt"></i> Résumé</h6>
                    </div>
                    <div class="card-body">
                        <?php if($livre['resume']): ?>
                        <p><?php echo nl2br(htmlspecialchars($livre['resume'])); ?></p>
                        <?php else: ?>
                        <p class="text-muted fst-italic">Aucun résumé fourni.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Prévisualisation PDF -->
                <div class="card mt-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="fas fa-file-pdf"></i> Prévisualisation</h6>
                        <small class="text-muted">Format: Times New Roman, 12pt, espace 1.5</small>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            La prévisualisation du PDF sera affichée ici avec les styles Times New Roman et espacement 1.5.
                        </div>
                        <div class="text-center">
                            <a href="<?php echo htmlspecialchars($livre['fichier_pdf']); ?>" 
                               target="_blank" class="btn btn-primary btn-lg">
                                <i class="fas fa-external-link-alt"></i> Ouvrir le PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Scripts Bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Gestion de la prévisualisation d'image
    document.addEventListener('DOMContentLoaded', function() {
        const coverInput = document.getElementById('couverture');
        const pdfInput = document.getElementById('fichier_pdf');
        
        if (coverInput) {
            coverInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file && file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('preview-cover');
                        preview.innerHTML = `<img src="${e.target.result}" class="img-fluid rounded" style="max-height: 200px;">`;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        
        // Validation du formulaire
        const form = document.getElementById('bookForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const titre = document.getElementById('titre').value.trim();
                const auteur = document.getElementById('auteur').value.trim();
                
                if (!titre || !auteur) {
                    e.preventDefault();
                    alert('Veuillez remplir les champs obligatoires (Titre et Auteur).');
                    return false;
                }
                
                if (pdfInput && !pdfInput.files.length && <?php echo $action == 'add' ? 'true' : 'false'; ?>) {
                    e.preventDefault();
                    alert('Veuillez sélectionner un fichier PDF.');
                    return false;
                }
                
                return true;
            });
        }
    });
    </script>
</body>
</html>