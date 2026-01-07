<?php
// dashboard/gestionnaire/generer_facture.php

// Définir le chemin absolu
define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));

// Activer l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Démarrer la session
session_start();

// Vérifier la connexion et le rôle
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

// Vérifier si l'utilisateur est un gestionnaire (rôle_id = 3 ou 4)
if (!isset($_SESSION['role_id']) || ($_SESSION['role_id'] != 3 && $_SESSION['role_id'] != 4)) {
    header('Location: ' . ROOT_PATH . '/auth/unauthorized.php');
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
    $pageTitle = "Gestionnaire - Générer Facture";
    
    // Récupérer l'ID du site si assigné
    $site_id = isset($_SESSION['site_id']) ? $_SESSION['site_id'] : null;
    $user_id = $_SESSION['user_id'];
    
    // Récupérer les étudiants
    $query = "SELECT e.id, e.matricule, e.nom, e.prenom, e.classe_id, 
                     c.nom as classe_nom, f.nom as filiere_nom
              FROM etudiants e
              LEFT JOIN classes c ON e.classe_id = c.id
              LEFT JOIN filieres f ON c.filiere_id = f.id
              WHERE e.statut = 'actif'";
    
    if ($site_id) {
        $query .= " AND e.site_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $etudiants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les types de frais
    $query = "SELECT id, nom, montant_base FROM types_frais ORDER BY nom";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $types_frais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les années académiques
    $query = "SELECT id, libelle FROM annees_academiques WHERE statut = 'active'";
    if ($site_id) {
        $query .= " AND site_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$site_id]);
    } else {
        $stmt = $db->prepare($query);
        $stmt->execute();
    }
    $annees_academiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Traitement du formulaire
    $success = '';
    $error = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $db->beginTransaction();
            
            $etudiant_id = $_POST['etudiant_id'];
            $type_frais_id = $_POST['type_frais_id'];
            $annee_academique_id = $_POST['annee_academique_id'];
            $montant_total = $_POST['montant_total'];
            $description = $_POST['description'];
            $date_echeance = $_POST['date_echeance'];
            $mode_paiement = $_POST['mode_paiement'];
            $montant_paye = $_POST['montant_paye'] ?? 0;
            $type_facture = $_POST['type_facture'];
            $remise = $_POST['remise'] ?? 0;
            
            // Récupérer les informations de l'étudiant
            $stmt = $db->prepare("SELECT site_id, matricule FROM etudiants WHERE id = ?");
            $stmt->execute([$etudiant_id]);
            $etudiant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Générer le numéro de facture
            $prefixe = 'FAC-' . date('Y') . '-';
            $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(numero_facture, 9) AS UNSIGNED)) as max_num 
                                  FROM factures 
                                  WHERE numero_facture LIKE ?");
            $stmt->execute([$prefixe . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $next_num = ($result['max_num'] ?? 0) + 1;
            $numero_facture = $prefixe . str_pad($next_num, 6, '0', STR_PAD_LEFT);
            
            // Insérer la facture
            $query = "INSERT INTO factures (
                        numero_facture,
                        etudiant_id,
                        site_id,
                        type_frais_id,
                        annee_academique_id,
                        montant_total,
                        remise,
                        montant_net,
                        montant_paye,
                        montant_restant,
                        description,
                        date_emission,
                        date_echeance,
                        mode_paiement,
                        type_facture,
                        statut,
                        emis_par,
                        date_creation
                      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, NOW())";
            
            $montant_net = $montant_total - $remise;
            $montant_restant = $montant_net - $montant_paye;
            $statut = ($montant_paye >= $montant_net) ? 'payee' : 
                     ($montant_paye > 0 ? 'partiel' : 'en_attente');
            
            $stmt = $db->prepare($query);
            $stmt->execute([
                $numero_facture,
                $etudiant_id,
                $etudiant['site_id'],
                $type_frais_id,
                $annee_academique_id,
                $montant_total,
                $remise,
                $montant_net,
                $montant_paye,
                $montant_restant,
                $description,
                $date_echeance,
                $mode_paiement,
                $type_facture,
                $statut,
                $user_id
            ]);
            
            $facture_id = $db->lastInsertId();
            
            // Si un paiement a été fait, créer l'enregistrement de paiement
            if ($montant_paye > 0) {
                $reference = 'PAY-' . date('ym') . str_pad($next_num, 6, '0', STR_PAD_LEFT);
                
                $query = "INSERT INTO paiements (
                            etudiant_id,
                            type_frais_id,
                            annee_academique_id,
                            reference,
                            montant,
                            mode_paiement,
                            date_paiement,
                            caissier_id,
                            facture_id,
                            statut,
                            date_creation
                          ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, 'valide', NOW())";
                
                $stmt = $db->prepare($query);
                $stmt->execute([
                    $etudiant_id,
                    $type_frais_id,
                    $annee_academique_id,
                    $reference,
                    $montant_paye,
                    $mode_paiement,
                    $user_id,
                    $facture_id
                ]);
            }
            
            $db->commit();
            
            $success = "Facture générée avec succès ! Numéro: $numero_facture";
            
            // Redirection vers la vue de la facture
            header("Location: voir_facture.php?id=$facture_id&success=1");
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur lors de la génération de la facture: " . $e->getMessage();
        }
    }
    
    // Récupérer le nom du site si assigné
    $site_nom = '';
    if ($site_id) {
        $stmt = $db->prepare("SELECT nom FROM sites WHERE id = ?");
        $stmt->execute([$site_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $site_nom = $result['nom'] ?? '';
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
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #3498db;
        --accent-color: #e74c3c;
        --success-color: #27ae60;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #f8f9fa;
        --card-bg: #ffffff;
        --text-color: #212529;
        --text-muted: #6c757d;
        --sidebar-bg: #2c3e50;
        --sidebar-text: #ffffff;
        --border-color: #dee2e6;
    }
    
    [data-theme="dark"] {
        --primary-color: #3498db;
        --secondary-color: #2980b9;
        --accent-color: #e74c3c;
        --success-color: #2ecc71;
        --warning-color: #f39c12;
        --info-color: #17a2b8;
        --bg-color: #121212;
        --card-bg: #1e1e1e;
        --text-color: #e0e0e0;
        --text-muted: #a0a0a0;
        --sidebar-bg: #1a1a1a;
        --sidebar-text: #ffffff;
        --border-color: #333333;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: var(--bg-color);
        color: var(--text-color);
        margin: 0;
        padding: 0;
        min-height: 100vh;
    }
    
    .app-container {
        display: flex;
        min-height: 100vh;
    }
    
    /* Sidebar - Similaire à factures.php */
    .sidebar {
        width: 250px;
        background-color: var(--sidebar-bg);
        color: var(--sidebar-text);
        position: fixed;
        height: 100vh;
        overflow-y: auto;
    }
    
    .sidebar-header {
        padding: 20px 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
    }
    
    .sidebar-logo {
        width: 50px;
        height: 50px;
        background: var(--secondary-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 10px;
    }
    
    .user-info {
        text-align: center;
        margin-bottom: 20px;
        padding: 0 15px;
    }
    
    .user-role {
        display: inline-block;
        padding: 4px 12px;
        background: var(--secondary-color);
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        margin-top: 5px;
    }
    
    .sidebar-nav {
        padding: 15px;
    }
    
    .nav-section {
        margin-bottom: 25px;
    }
    
    .nav-section-title {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: rgba(255, 255, 255, 0.6);
        margin-bottom: 10px;
        padding: 0 10px;
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        padding: 10px 15px;
        color: var(--sidebar-text);
        text-decoration: none;
        border-radius: 5px;
        margin-bottom: 5px;
        transition: all 0.3s;
    }
    
    .nav-link:hover, .nav-link.active {
        background-color: var(--secondary-color);
        color: white;
    }
    
    .nav-link i {
        width: 20px;
        margin-right: 10px;
        text-align: center;
    }
    
    .main-content {
        flex: 1;
        margin-left: 250px;
        padding: 20px;
        min-height: 100vh;
    }
    
    /* Formulaires */
    .form-section {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 25px;
        margin-bottom: 20px;
    }
    
    .form-section h4 {
        color: var(--primary-color);
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--border-color);
    }
    
    .form-label {
        font-weight: 500;
        color: var(--text-color);
        margin-bottom: 8px;
    }
    
    .form-control, .form-select {
        background-color: var(--card-bg);
        color: var(--text-color);
        border: 1px solid var(--border-color);
        padding: 10px 15px;
        border-radius: 6px;
        transition: all 0.3s;
    }
    
    .form-control:focus, .form-select:focus {
        background-color: var(--card-bg);
        color: var(--text-color);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(52, 152, 219, 0.25);
    }
    
    .form-control:disabled {
        background-color: rgba(0, 0, 0, 0.05);
        color: var(--text-muted);
    }
    
    [data-theme="dark"] .form-control:disabled {
        background-color: rgba(255, 255, 255, 0.05);
    }
    
    /* Calculs financiers */
    .calcul-box {
        background: rgba(52, 152, 219, 0.1);
        border: 1px solid var(--secondary-color);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .calcul-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px dashed var(--border-color);
    }
    
    .calcul-row:last-child {
        border-bottom: none;
        font-weight: bold;
        font-size: 1.1em;
    }
    
    /* Boutons */
    .btn-action {
        padding: 10px 30px;
        font-weight: 500;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            width: 70px;
        }
        
        .sidebar-header, .user-info, .nav-section-title, .nav-link span {
            display: none;
        }
        
        .nav-link {
            justify-content: center;
            padding: 15px;
        }
        
        .nav-link i {
            margin-right: 0;
            font-size: 18px;
        }
        
        .main-content {
            margin-left: 70px;
            padding: 15px;
        }
    }
</style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <h5 class="mt-2 mb-1">ISGI FINANCES</h5>
                <div class="user-role">Gestionnaire</div>
                <?php if($site_nom): ?>
                <small><?php echo htmlspecialchars($site_nom); ?></small>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <p class="mb-1"><?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Utilisateur'; ?></p>
                <small>Gestion Financière</small>
            </div>
            
            <div class="sidebar-nav">
                <div class="nav-section">
                    <div class="nav-section-title">Navigation</div>
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="factures.php" class="nav-link">
                        <i class="fas fa-file-invoice"></i>
                        <span>Factures</span>
                    </a>
                    <a href="generer_facture.php" class="nav-link active">
                        <i class="fas fa-plus-circle"></i>
                        <span>Nouvelle Facture</span>
                    </a>
                </div>
                
                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button class="btn btn-outline-light w-100 mb-2" onclick="toggleTheme()">
                        <i class="fas fa-moon"></i> <span>Mode Sombre</span>
                    </button>
                    <a href="../../auth/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Contenu Principal -->
        <div class="main-content">
            <!-- En-tête -->
            <div class="content-header mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0">
                            <i class="fas fa-file-invoice me-2"></i>
                            Générer une Nouvelle Facture
                        </h2>
                        <p class="text-muted mb-0">
                            Créer une facture pour un étudiant
                        </p>
                    </div>
                    <div>
                        <a href="factures.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Retour aux factures
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Formulaire de génération de facture -->
            <form method="POST" action="">
                <div class="form-section">
                    <h4><i class="fas fa-user-graduate me-2"></i>Informations de l'Étudiant</h4>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="etudiant_id" class="form-label">Étudiant *</label>
                            <select class="form-select" id="etudiant_id" name="etudiant_id" required>
                                <option value="">Sélectionner un étudiant</option>
                                <?php foreach($etudiants as $etudiant): ?>
                                <option value="<?php echo $etudiant['id']; ?>">
                                    <?php echo htmlspecialchars($etudiant['matricule'] . ' - ' . $etudiant['nom'] . ' ' . $etudiant['prenom']); ?>
                                    <?php if($etudiant['filiere_nom']): ?>
                                     (<?php echo htmlspecialchars($etudiant['filiere_nom']); ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="annee_academique_id" class="form-label">Année Académique *</label>
                            <select class="form-select" id="annee_academique_id" name="annee_academique_id" required>
                                <option value="">Sélectionner une année</option>
                                <?php foreach($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>">
                                    <?php echo htmlspecialchars($annee['libelle']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="type_facture" class="form-label">Type de Facture *</label>
                            <select class="form-select" id="type_facture" name="type_facture" required>
                                <option value="scolarite">Scolarité</option>
                                <option value="inscription">Inscription</option>
                                <option value="examen">Droits d'examen</option>
                                <option value="stage">Frais de stage</option>
                                <option value="divers">Frais divers</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="type_frais_id" class="form-label">Type de Frais *</label>
                            <select class="form-select" id="type_frais_id" name="type_frais_id" required>
                                <option value="">Sélectionner un type de frais</option>
                                <?php foreach($types_frais as $type): ?>
                                <option value="<?php echo $type['id']; ?>" data-montant="<?php echo $type['montant_base']; ?>">
                                    <?php echo htmlspecialchars($type['nom'] . ' (' . number_format($type['montant_base'], 0, ',', ' ') . ' FCFA)'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h4><i class="fas fa-money-bill-wave me-2"></i>Détails Financiers</h4>
                    
                    <div class="calcul-box">
                        <div class="calcul-row">
                            <span>Montant total :</span>
                            <span id="montant_total_label">0 FCFA</span>
                            <input type="hidden" id="montant_total" name="montant_total" value="0">
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="remise" class="form-label">Remise (FCFA)</label>
                                <input type="number" class="form-control" id="remise" name="remise" value="0" min="0" step="1000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Montant après remise</label>
                                <div class="form-control" id="montant_net_label" style="background-color: #f8f9fa; font-weight: bold;">
                                    0 FCFA
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="montant_paye" class="form-label">Montant payé (FCFA)</label>
                                <input type="number" class="form-control" id="montant_paye" name="montant_paye" value="0" min="0" step="1000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reste à payer</label>
                                <div class="form-control" id="montant_restant_label" style="background-color: #f8f9fa; font-weight: bold; color: #dc3545;">
                                    0 FCFA
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="mode_paiement" class="form-label">Mode de paiement</label>
                            <select class="form-select" id="mode_paiement" name="mode_paiement">
                                <option value="espece">Espèces</option>
                                <option value="virement">Virement bancaire</option>
                                <option value="mtn_momo">MTN Mobile Money</option>
                                <option value="airtel_money">Airtel Money</option>
                                <option value="cheque">Chèque</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="date_echeance" class="form-label">Date d'échéance</label>
                            <input type="date" class="form-control" id="date_echeance" name="date_echeance" 
                                   value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description / Notes</label>
                        <textarea class="form-control" id="description" name="description" rows="3" 
                                  placeholder="Description détaillée de la facture..."></textarea>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between mt-4">
                    <a href="factures.php" class="btn btn-secondary btn-action">
                        <i class="fas fa-times"></i> Annuler
                    </a>
                    <button type="submit" class="btn btn-primary btn-action">
                        <i class="fas fa-save"></i> Générer la Facture
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Scripts JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/fr.js"></script>
    
    <script>
    // Fonction pour basculer entre mode sombre et clair
    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        html.setAttribute('data-theme', newTheme);
        document.cookie = `isgi_theme=${newTheme}; max-age=${30*24*60*60}; path=/`;
        
        const button = event.target.closest('button');
        if (button) {
            if (newTheme === 'dark') {
                button.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                button.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    }
    
    // Initialiser Select2
    $(document).ready(function() {
        $('#etudiant_id').select2({
            placeholder: "Rechercher un étudiant...",
            language: "fr",
            width: '100%'
        });
        
        $('#type_frais_id').select2({
            placeholder: "Sélectionner un type de frais...",
            language: "fr",
            width: '100%'
        });
        
        // Initialiser le thème
        const theme = document.cookie.replace(/(?:(?:^|.*;\s*)isgi_theme\s*=\s*([^;]*).*$)|^.*$/, "$1") || 'light';
        document.documentElement.setAttribute('data-theme', theme);
        
        const themeButton = document.querySelector('button[onclick="toggleTheme()"]');
        if (themeButton) {
            if (theme === 'dark') {
                themeButton.innerHTML = '<i class="fas fa-sun"></i> <span>Mode Clair</span>';
            } else {
                themeButton.innerHTML = '<i class="fas fa-moon"></i> <span>Mode Sombre</span>';
            }
        }
    });
    
    // Fonctions de calcul financier
    function updateCalculations() {
        const typeFraisSelect = document.getElementById('type_frais_id');
        const selectedOption = typeFraisSelect.options[typeFraisSelect.selectedIndex];
        const montantBase = selectedOption ? parseFloat(selectedOption.getAttribute('data-montant') || 0) : 0;
        
        const remise = parseFloat(document.getElementById('remise').value) || 0;
        const montantPaye = parseFloat(document.getElementById('montant_paye').value) || 0;
        
        // Calculs
        const montantTotal = montantBase;
        const montantNet = montantTotal - remise;
        const montantRestant = montantNet - montantPaye;
        
        // Mise à jour des champs
        document.getElementById('montant_total').value = montantTotal;
        document.getElementById('montant_total_label').textContent = formatMoney(montantTotal);
        document.getElementById('montant_net_label').textContent = formatMoney(montantNet);
        document.getElementById('montant_restant_label').textContent = formatMoney(montantRestant);
        
        // Couleur du reste à payer
        const restantLabel = document.getElementById('montant_restant_label');
        if (montantRestant > 0) {
            restantLabel.style.color = '#dc3545'; // Rouge
        } else if (montantRestant === 0) {
            restantLabel.style.color = '#198754'; // Vert
        } else {
            restantLabel.style.color = '#6c757d'; // Gris
        }
    }
    
    function formatMoney(amount) {
        return new Intl.NumberFormat('fr-FR').format(amount) + ' FCFA';
    }
    
    // Écouteurs d'événements
    document.getElementById('type_frais_id').addEventListener('change', updateCalculations);
    document.getElementById('remise').addEventListener('input', updateCalculations);
    document.getElementById('montant_paye').addEventListener('input', updateCalculations);
    
    // Validation du formulaire
    document.querySelector('form').addEventListener('submit', function(e) {
        const montantTotal = parseFloat(document.getElementById('montant_total').value) || 0;
        const montantPaye = parseFloat(document.getElementById('montant_paye').value) || 0;
        const montantNet = montantTotal - (parseFloat(document.getElementById('remise').value) || 0);
        
        if (montantPaye > montantNet) {
            e.preventDefault();
            alert('Le montant payé ne peut pas être supérieur au montant net !');
            document.getElementById('montant_paye').focus();
        }
    });
    
    // Initialiser les calculs
    updateCalculations();
    </script>
</body>
</html>