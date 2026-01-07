<?php
// dashboard/admin_principal/editeur_document.php

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

try {
    // Récupérer la connexion à la base
    $db = Database::getInstance()->getConnection();
    
    // Définir le titre de la page
    $pageTitle = "Éditeur de Document";
    
    // Initialiser les variables
    $message = '';
    $error = '';
    $action = isset($_GET['action']) ? $_GET['action'] : 'new';
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $document = null;
    
    // Actions
    switch ($action) {
        case 'edit':
            // Récupérer le document à éditer
            $stmt = $db->prepare("SELECT * FROM bibliotheque_documents WHERE id = ? AND auteur_id = ?");
            $stmt->execute([$id, $_SESSION['user_id']]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$document) {
                $error = "Document non trouvé ou vous n'avez pas l'autorisation de l'éditer.";
                $action = 'list';
            }
            break;
            
        case 'save':
            // Sauvegarder le document
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $titre = $_POST['titre'] ?? 'Nouveau Document';
                $contenu_html = $_POST['contenu_html'] ?? '';
                $contenu_texte = strip_tags($contenu_html);
                $nombre_pages = intval($_POST['nombre_pages'] ?? 0);
                $statut = $_POST['statut'] ?? 'brouillon';
                
                // Calculer automatiquement le nombre de pages (environ 500 mots par page)
                if ($nombre_pages == 0) {
                    $word_count = str_word_count($contenu_texte);
                    $nombre_pages = ceil($word_count / 500);
                }
                
                if ($id > 0) {
                    // Mettre à jour le document existant
                    $stmt = $db->prepare("UPDATE bibliotheque_documents SET 
                        titre = ?, contenu_html = ?, contenu_texte = ?, 
                        nombre_pages = ?, statut = ?, date_modification = NOW(),
                        derniere_sauvegarde = NOW()
                        WHERE id = ? AND auteur_id = ?");
                    
                    $stmt->execute([
                        $titre, $contenu_html, $contenu_texte,
                        $nombre_pages, $statut, $id, $_SESSION['user_id']
                    ]);
                    
                    // Sauvegarder une version
                    $stmt = $db->prepare("INSERT INTO bibliotheque_sauvegardes 
                        (document_id, contenu_html, contenu_texte, version, type_sauvegarde)
                        VALUES (?, ?, ?, 
                        (SELECT COALESCE(MAX(version), 0) + 1 FROM bibliotheque_sauvegardes WHERE document_id = ?),
                        'manuel')");
                    $stmt->execute([$id, $contenu_html, $contenu_texte, $id]);
                    
                    $message = "Document sauvegardé avec succès!";
                } else {
                    // Créer un nouveau document
                    $stmt = $db->prepare("INSERT INTO bibliotheque_documents 
                        (titre, auteur_id, contenu_html, contenu_texte, style_css, 
                         site_id, statut, nombre_pages, version)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    
                    // Style par défaut: Times New Roman, espace 1.5, alignement gauche
                    $style_css = 'font-family: "Times New Roman", Times, serif; line-height: 1.5; text-align: left; direction: ltr;';
                    
                    $stmt->execute([
                        $titre,
                        $_SESSION['user_id'],
                        $contenu_html,
                        $contenu_texte,
                        $style_css,
                        $_SESSION['site_id'] ?? 1,
                        $statut,
                        $nombre_pages
                    ]);
                    
                    $id = $db->lastInsertId();
                    $message = "Document créé avec succès!";
                }
                
                // Rediriger vers l'édition
                header("Location: editeur_document.php?action=edit&id=$id&message=" . urlencode($message));
                exit();
            }
            break;
            
        case 'publish':
            // Publier le document (minimum 30 pages)
            $stmt = $db->prepare("SELECT * FROM bibliotheque_documents WHERE id = ? AND auteur_id = ?");
            $stmt->execute([$id, $_SESSION['user_id']]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($document && $document['nombre_pages'] >= 30) {
                $stmt = $db->prepare("UPDATE bibliotheque_documents SET 
                    statut = 'publie', date_publication = NOW()
                    WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Document publié avec succès!";
            } else {
                $error = "Le document doit avoir au moins 30 pages pour être publié.";
            }
            break;
            
        case 'list':
            // Liste des documents de l'utilisateur
            $stmt = $db->prepare("SELECT * FROM bibliotheque_documents 
                WHERE auteur_id = ? ORDER BY date_creation DESC");
            $stmt->execute([$_SESSION['user_id']]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        default: // 'new'
            // Préparer un nouveau document
            $document = [
                'titre' => 'Nouveau Document',
                'contenu_html' => '',
                'statut' => 'brouillon',
                'nombre_pages' => 0
            ];
            break;
    }
    
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
    
    <!-- Quill Editor CSS -->
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    
    <style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .editor-container {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    
    .editor-toolbar {
        background: #f8f9fa;
        padding: 10px;
        border-bottom: 1px solid #dee2e6;
        position: sticky;
        top: 0;
        z-index: 100;
    }
    
    .ql-toolbar.ql-snow {
        border: none !important;
        border-bottom: 1px solid #dee2e6 !important;
    }
    
    .ql-container.ql-snow {
        border: none !important;
        font-family: "Times New Roman", Times, serif;
        font-size: 12pt;
        line-height: 1.5;
    }
    
    .ql-editor {
        min-height: 600px;
        padding: 20px;
        background: white;
    }
    
    .editor-sidebar {
        background: #f8f9fa;
        border-right: 1px solid #dee2e6;
        height: 100vh;
        overflow-y: auto;
        position: fixed;
        width: 300px;
        left: -300px;
        transition: left 0.3s;
        z-index: 1000;
    }
    
    .editor-sidebar.show {
        left: 0;
    }
    
    .editor-main {
        flex: 1;
        margin-left: 0;
        transition: margin-left 0.3s;
    }
    
    .editor-main.sidebar-open {
        margin-left: 300px;
    }
    
    .document-list {
        max-height: 400px;
        overflow-y: auto;
    }
    
    .document-item {
        border-left: 3px solid #dee2e6;
        transition: all 0.3s;
    }
    
    .document-item:hover {
        background: #e9ecef;
        border-left-color: #3498db;
    }
    
    .document-item.active {
        background: #e3f2fd;
        border-left-color: #2196f3;
    }
    
    .status-badge {
        font-size: 0.7em;
        padding: 2px 6px;
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
        opacity: 0;
        transition: opacity 0.3s;
    }
    
    .auto-save.show {
        opacity: 1;
    }
    
    .font-selector {
        min-width: 150px;
    }
    
    .color-picker {
        width: 30px;
        height: 30px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }
    
    @media (max-width: 768px) {
        .editor-sidebar {
            width: 100%;
            left: -100%;
        }
        
        .editor-main.sidebar-open {
            margin-left: 0;
        }
    }
    </style>
</head>
<body>
    <?php if($action == 'list'): ?>
    <!-- Inclure la sidebar du dashboard -->
    <?php include 'dashboard.php'; ?>
    
    <div class="main-content">
        <div class="content-header mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-0">
                        <i class="fas fa-file-alt me-2"></i>
                        Mes Documents
                    </h2>
                    <p class="text-muted mb-0">Gérez vos documents et livrets</p>
                </div>
                <div>
                    <a href="bibliotheque.php" class="btn btn-outline-primary me-2">
                        <i class="fas fa-book"></i> Bibliothèque
                    </a>
                    <a href="editeur_document.php?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Nouveau Document
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Messages -->
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
        
        <!-- Liste des documents -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Documents (<?php echo count($documents ?? []); ?>)</h5>
            </div>
            <div class="card-body">
                <?php if(empty($documents)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                    <h4>Aucun document</h4>
                    <p class="text-muted">Créez votre premier document pour commencer.</p>
                    <a href="editeur_document.php?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Créer un Document
                    </a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Titre</th>
                                <th>Statut</th>
                                <th>Pages</th>
                                <th>Créé le</th>
                                <th>Modifié le</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($documents as $doc): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($doc['titre']); ?></strong>
                                    <?php if($doc['statut'] == 'publie'): ?>
                                    <br><small class="text-muted">Publié le: <?php echo date('d/m/Y', strtotime($doc['date_publication'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $badge_class = [
                                        'brouillon' => 'secondary',
                                        'en_revision' => 'warning',
                                        'publie' => 'success',
                                        'archive' => 'info'
                                    ][$doc['statut']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $badge_class; ?>">
                                        <?php echo ucfirst($doc['statut']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark">
                                        <?php echo $doc['nombre_pages']; ?> pages
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($doc['date_creation'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($doc['date_modification'])); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="editeur_document.php?action=edit&id=<?php echo $doc['id']; ?>" 
                                           class="btn btn-outline-primary" title="Éditer">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if($doc['statut'] != 'publie' && $doc['nombre_pages'] >= 30): ?>
                                        <a href="editeur_document.php?action=publish&id=<?php echo $doc['id']; ?>" 
                                           class="btn btn-outline-success" 
                                           onclick="return confirm('Publier ce document ?')" title="Publier">
                                            <i class="fas fa-upload"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a href="bibliotheque.php?action=view_document&id=<?php echo $doc['id']; ?>" 
                                           class="btn btn-outline-info" title="Prévisualiser">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Interface d'édition -->
    <div class="editor-container">
        <!-- Barre d'outils supérieure -->
        <div class="editor-toolbar">
            <div class="container-fluid">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-sm btn-outline-secondary me-2" id="toggle-sidebar">
                            <i class="fas fa-bars"></i>
                        </button>
                        
                        <input type="text" id="document-title" 
                               class="form-control form-control-sm" 
                               style="width: 250px;" 
                               placeholder="Titre du document..."
                               value="<?php echo htmlspecialchars($document['titre'] ?? 'Nouveau Document'); ?>">
                        
                        <select id="document-status" class="form-select form-select-sm ms-2" style="width: 120px;">
                            <option value="brouillon" <?php echo ($document['statut'] ?? 'brouillon') == 'brouillon' ? 'selected' : ''; ?>>Brouillon</option>
                            <option value="en_revision" <?php echo ($document['statut'] ?? 'brouillon') == 'en_revision' ? 'selected' : ''; ?>>En révision</option>
                            <option value="publie" <?php echo ($document['statut'] ?? 'brouillon') == 'publie' ? 'selected' : ''; ?>>Publié</option>
                        </select>
                    </div>
                    
                    <div class="d-flex align-items-center">
                        <!-- Sélecteur de police -->
                        <select id="font-selector" class="form-select form-select-sm me-2 font-selector">
                            <option value="Times New Roman" selected>Times New Roman</option>
                            <option value="Arial">Arial</option>
                            <option value="Georgia">Georgia</option>
                            <option value="Courier New">Courier New</option>
                            <option value="Verdana">Verdana</option>
                        </select>
                        
                        <!-- Sélecteur de taille -->
                        <select id="font-size" class="form-select form-select-sm me-2" style="width: 70px;">
                            <option value="10px">10pt</option>
                            <option value="11px">11pt</option>
                            <option value="12px" selected>12pt</option>
                            <option value="14px">14pt</option>
                            <option value="16px">16pt</option>
                            <option value="18px">18pt</option>
                        </select>
                        
                        <!-- Couleurs -->
                        <input type="color" id="text-color" class="color-picker me-2" value="#000000" title="Couleur du texte">
                        <input type="color" id="highlight-color" class="color-picker me-2" value="#ffff00" title="Surlignage">
                        
                        <div class="vr me-2"></div>
                        
                        <!-- Boutons d'action -->
                        <button id="save-document" class="btn btn-sm btn-primary me-2">
                            <i class="fas fa-save"></i> Sauvegarder
                        </button>
                        
                        <?php if(isset($id) && $id > 0 && ($document['nombre_pages'] ?? 0) >= 30): ?>
                        <button id="publish-document" class="btn btn-sm btn-success me-2">
                            <i class="fas fa-upload"></i> Publier
                        </button>
                        <?php endif; ?>
                        
                        <a href="editeur_document.php?action=list" class="btn btn-sm btn-secondary">
                            <i class="fas fa-times"></i> Fermer
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Barre d'outils Quill -->
        <div id="quill-toolbar">
            <span class="ql-formats">
                <select class="ql-font"></select>
                <select class="ql-size"></select>
            </span>
            <span class="ql-formats">
                <button class="ql-bold"></button>
                <button class="ql-italic"></button>
                <button class="ql-underline"></button>
                <button class="ql-strike"></button>
            </span>
            <span class="ql-formats">
                <select class="ql-color"></select>
                <select class="ql-background"></select>
            </span>
            <span class="ql-formats">
                <button class="ql-list" value="ordered"></button>
                <button class="ql-list" value="bullet"></button>
                <button class="ql-indent" value="-1"></button>
                <button class="ql-indent" value="+1"></button>
            </span>
            <span class="ql-formats">
                <button class="ql-direction" value="rtl"></button>
                <select class="ql-align"></select>
            </span>
            <span class="ql-formats">
                <button class="ql-link"></button>
                <button class="ql-image"></button>
                <button class="ql-video"></button>
            </span>
            <span class="ql-formats">
                <button class="ql-clean"></button>
            </span>
        </div>
        
        <!-- Éditeur principal -->
        <div class="editor-main" id="editor-main">
            <div id="quill-editor">
                <?php echo $document['contenu_html'] ?? '<p><br></p>'; ?>
            </div>
        </div>
        
        <!-- Sidebar -->
        <div class="editor-sidebar" id="editor-sidebar">
            <div class="sidebar-header p-3 border-bottom">
                <h6 class="mb-0">
                    <i class="fas fa-cog me-2"></i>
                    Propriétés du Document
                </h6>
                <button class="btn btn-sm btn-close position-absolute top-0 end-0 m-2" id="close-sidebar"></button>
            </div>
            
            <div class="sidebar-content p-3">
                <!-- Informations du document -->
                <div class="mb-4">
                    <h6 class="mb-2"><i class="fas fa-info-circle me-2"></i> Informations</h6>
                    <div class="mb-2">
                        <small class="text-muted d-block">Pages:</small>
                        <div class="d-flex align-items-center">
                            <input type="number" id="page-count" class="form-control form-control-sm" 
                                   value="<?php echo $document['nombre_pages'] ?? 0; ?>" min="0">
                            <span class="ms-2 small text-muted">/30 minimum</span>
                        </div>
                    </div>
                    
                    <div class="mb-2">
                        <small class="text-muted d-block">Mots:</small>
                        <div id="word-count" class="fw-bold">0</div>
                    </div>
                    
                    <div class="mb-2">
                        <small class="text-muted d-block">Caractères:</small>
                        <div id="char-count" class="fw-bold">0</div>
                    </div>
                </div>
                
                <!-- Sauvegardes automatiques -->
                <div class="mb-4">
                    <h6 class="mb-2"><i class="fas fa-history me-2"></i> Sauvegardes</h6>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="auto-save-toggle" checked>
                        <label class="form-check-label" for="auto-save-toggle">Sauvegarde automatique</label>
                    </div>
                    
                    <div class="small text-muted">
                        <div>Intervalle: <span id="save-interval">30</span> secondes</div>
                        <div id="last-save">Dernière sauvegarde: jamais</div>
                    </div>
                    
                    <button id="manual-save" class="btn btn-sm btn-outline-primary w-100 mt-2">
                        <i class="fas fa-save"></i> Sauvegarder maintenant
                    </button>
                </div>
                
                <!-- Versions -->
                <?php if(isset($id) && $id > 0): ?>
                <div class="mb-4">
                    <h6 class="mb-2"><i class="fas fa-code-branch me-2"></i> Versions</h6>
                    <div class="document-list">
                        <?php
                        $stmt = $db->prepare("SELECT * FROM bibliotheque_sauvegardes 
                            WHERE document_id = ? ORDER BY date_sauvegarde DESC LIMIT 5");
                        $stmt->execute([$id]);
                        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach($versions as $version): ?>
                        <div class="document-item p-2 mb-1">
                            <div class="small">
                                <div class="fw-bold">Version <?php echo $version['version']; ?></div>
                                <div class="text-muted">
                                    <?php echo date('d/m/Y H:i', strtotime($version['date_sauvegarde'])); ?>
                                </div>
                                <div class="text-muted">
                                    <?php echo $version['type_sauvegarde'] == 'auto' ? 'Auto' : 'Manuelle'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Aide -->
                <div>
                    <h6 class="mb-2"><i class="fas fa-question-circle me-2"></i> Aide</h6>
                    <div class="small text-muted">
                        <p><strong>Publication:</strong> 30 pages minimum</p>
                        <p><strong>Style par défaut:</strong> Times New Roman, 12pt, espace 1.5</p>
                        <p><strong>Sauvegarde:</strong> Auto toutes les 30 secondes</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Compteurs -->
        <div class="page-counter" id="page-counter">
            <i class="fas fa-file-alt"></i> 
            <span id="current-pages"><?php echo $document['nombre_pages'] ?? 0; ?></span> pages
        </div>
        
        <div class="auto-save" id="auto-save-message">
            <i class="fas fa-check"></i> Sauvegarde automatique réussie
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Quill Editor JS -->
    <script src="https://cdn.quilljs.com/1.3.7/quill.js"></script>
    
    <script>
    <?php if($action != 'list'): ?>
    // Initialiser l'éditeur Quill
    const quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: {
            toolbar: '#quill-toolbar'
        },
        placeholder: 'Commencez à écrire votre document...',
        formats: [
            'bold', 'italic', 'underline', 'strike',
            'color', 'background',
            'list', 'bullet', 'indent',
            'link', 'image', 'video',
            'align', 'direction',
            'font', 'size'
        ]
    });
    
    // Définir le style par défaut
    quill.format('font', 'Times New Roman');
    quill.format('size', '12px');
    quill.format('align', 'left');
    quill.format('direction', 'ltr');
    
    // Variables globales
    let autoSaveInterval;
    let lastSaveTime = null;
    let documentId = <?php echo $id > 0 ? $id : 'null'; ?>;
    let saveInProgress = false;
    
    // Gestion de la sidebar
    const sidebar = document.getElementById('editor-sidebar');
    const mainContent = document.getElementById('editor-main');
    const toggleSidebarBtn = document.getElementById('toggle-sidebar');
    const closeSidebarBtn = document.getElementById('close-sidebar');
    
    toggleSidebarBtn.addEventListener('click', () => {
        sidebar.classList.add('show');
        mainContent.classList.add('sidebar-open');
    });
    
    closeSidebarBtn.addEventListener('click', () => {
        sidebar.classList.remove('show');
        mainContent.classList.remove('sidebar-open');
    });
    
    // Mettre à jour les compteurs
    function updateCounters() {
        const text = quill.getText();
        const words = text.trim().split(/\s+/).filter(word => word.length > 0).length;
        const chars = text.length;
        
        document.getElementById('word-count').textContent = words;
        document.getElementById('char-count').textContent = chars;
        
        // Calculer les pages (environ 500 mots par page)
        const pages = Math.max(1, Math.ceil(words / 500));
        document.getElementById('current-pages').textContent = pages;
        document.getElementById('page-count').value = pages;
    }
    
    // Sauvegarder le document
    async function saveDocument(isAutoSave = false) {
        if (saveInProgress) return;
        
        saveInProgress = true;
        
        try {
            const title = document.getElementById('document-title').value;
            const content = quill.root.innerHTML;
            const status = document.getElementById('document-status').value;
            const pageCount = document.getElementById('page-count').value;
            
            const formData = new FormData();
            formData.append('titre', title);
            formData.append('contenu_html', content);
            formData.append('statut', status);
            formData.append('nombre_pages', pageCount);
            
            if (documentId) {
                formData.append('id', documentId);
            }
            
            const response = await fetch('editeur_document.php?action=save', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.text();
            
            // Mettre à jour l'heure de dernière sauvegarde
            lastSaveTime = new Date();
            document.getElementById('last-save').textContent = 
                'Dernière sauvegarde: ' + lastSaveTime.toLocaleTimeString();
            
            if (!isAutoSave) {
                // Afficher le message de sauvegarde
                const saveMsg = document.getElementById('auto-save-message');
                saveMsg.textContent = 'Sauvegarde réussie!';
                saveMsg.classList.add('show');
                
                setTimeout(() => {
                    saveMsg.classList.remove('show');
                }, 3000);
            }
            
            // Si c'est une nouvelle sauvegarde, récupérer l'ID
            if (!documentId && result.includes('success')) {
                // Extraire l'ID de la réponse
                const match = result.match(/id=(\d+)/);
                if (match) {
                    documentId = match[1];
                }
            }
            
        } catch (error) {
            console.error('Erreur de sauvegarde:', error);
            if (!isAutoSave) {
                alert('Erreur lors de la sauvegarde: ' + error.message);
            }
        } finally {
            saveInProgress = false;
        }
    }
    
    // Configurer la sauvegarde automatique
    function setupAutoSave() {
        const autoSaveToggle = document.getElementById('auto-save-toggle');
        const saveInterval = 30000; // 30 secondes
        
        if (autoSaveToggle.checked) {
            autoSaveInterval = setInterval(() => {
                if (quill.getText().trim().length > 0) {
                    saveDocument(true);
                }
            }, saveInterval);
            
            document.getElementById('save-interval').textContent = saveInterval / 1000;
        } else {
            clearInterval(autoSaveInterval);
        }
    }
    
    // Gestion des événements
    document.getElementById('save-document').addEventListener('click', () => saveDocument(false));
    document.getElementById('manual-save').addEventListener('click', () => saveDocument(false));
    
    if (document.getElementById('publish-document')) {
        document.getElementById('publish-document').addEventListener('click', () => {
            const pageCount = parseInt(document.getElementById('page-count').value);
            if (pageCount >= 30) {
                if (confirm('Publier ce document ? Il sera accessible dans la bibliothèque.')) {
                    window.location.href = `editeur_document.php?action=publish&id=${documentId}`;
                }
            } else {
                alert('Le document doit avoir au moins 30 pages pour être publié.');
            }
        });
    }
    
    // Gestion du sélecteur de police
    document.getElementById('font-selector').addEventListener('change', (e) => {
        quill.format('font', e.target.value);
    });
    
    // Gestion du sélecteur de taille
    document.getElementById('font-size').addEventListener('change', (e) => {
        quill.format('size', e.target.value);
    });
    
    // Gestion des couleurs
    document.getElementById('text-color').addEventListener('change', (e) => {
        quill.format('color', e.target.value);
    });
    
    document.getElementById('highlight-color').addEventListener('change', (e) => {
        quill.format('background', e.target.value);
    });
    
    // Mettre à jour les compteurs lors de la frappe
    quill.on('text-change', updateCounters);
    
    // Sauvegarder avant de quitter
    window.addEventListener('beforeunload', (e) => {
        if (quill.getText().trim().length > 0) {
            e.preventDefault();
            e.returnValue = 'Vous avez des modifications non sauvegardées. Voulez-vous vraiment quitter ?';
        }
    });
    
    // Initialiser
    document.addEventListener('DOMContentLoaded', () => {
        updateCounters();
        setupAutoSave();
        
        document.getElementById('auto-save-toggle').addEventListener('change', setupAutoSave);
        
        // Définir l'heure de dernière sauvegarde
        if (<?php echo isset($document['derniere_sauvegarde']) && $document['derniere_sauvegarde'] ? 'true' : 'false'; ?>) {
            lastSaveTime = new Date('<?php echo $document["derniere_sauvegarde"] ?? ""; ?>');
            document.getElementById('last-save').textContent = 
                'Dernière sauvegarde: ' + lastSaveTime.toLocaleString();
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>