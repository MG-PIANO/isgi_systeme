<?php
// dashboard/surveillant/scanner_qr.php

define('ROOT_PATH', dirname(dirname(dirname(__FILE__))));
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] != 6) {
    header('Location: ' . ROOT_PATH . '/auth/login.php');
    exit();
}

$pageTitle = "Scanner QR Code";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
    #preview {
        width: 100%;
        max-width: 500px;
        height: 400px;
        border: 2px dashed #ccc;
        border-radius: 10px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8f9fa;
        position: relative;
        overflow: hidden;
    }
    
    #preview video {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .scan-area {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 250px;
        height: 250px;
        border: 3px solid #00ff00;
        border-radius: 10px;
        box-shadow: 0 0 0 1000px rgba(0, 0, 0, 0.5);
        z-index: 10;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { border-color: #00ff00; }
        50% { border-color: #00cc00; }
        100% { border-color: #00ff00; }
    }
    
    .result-card {
        max-width: 500px;
        margin: 20px auto;
        display: none;
    }
    
    #statusIndicator {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 10px;
    }
    
    .status-success { background-color: #28a745; }
    .status-error { background-color: #dc3545; }
    .status-warning { background-color: #ffc107; }
    
    .history-item {
        border-left: 4px solid;
        margin-bottom: 10px;
        padding-left: 10px;
    }
    
    .history-success { border-color: #28a745; }
    .history-error { border-color: #dc3545; }
    .history-warning { border-color: #ffc107; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-user-shield me-2"></i>
                Scanner QR Code
            </a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-arrow-left me-1"></i> Retour
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-qrcode me-2"></i>
                            Scanner QR Code Étudiant
                        </h4>
                    </div>
                    <div class="card-body text-center">
                        <!-- Zone de scan -->
                        <div id="preview">
                            <div class="scan-area"></div>
                            <video id="scanner"></video>
                            <div id="scanner-overlay" class="d-none">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Chargement...</span>
                                </div>
                                <p class="mt-2">Initialisation du scanner...</p>
                            </div>
                        </div>
                        
                        <!-- Contrôles -->
                        <div class="mt-4">
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-success" id="startScanner">
                                    <i class="fas fa-play me-1"></i> Démarrer Scan
                                </button>
                                <button type="button" class="btn btn-warning" id="stopScanner" disabled>
                                    <i class="fas fa-stop me-1"></i> Arrêter
                                </button>
                                <button type="button" class="btn btn-info" id="switchCamera">
                                    <i class="fas fa-camera-rotate me-1"></i> Changer Caméra
                                </button>
                                <button type="button" class="btn btn-secondary" id="uploadImage">
                                    <i class="fas fa-upload me-1"></i> Importer Image
                                </button>
                            </div>
                        </div>
                        
                        <!-- Sélection du type de présence -->
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <label class="form-label">Type de présence</label>
                                <select class="form-select" id="presenceType">
                                    <option value="entree_ecole">Entrée École</option>
                                    <option value="sortie_ecole">Sortie École</option>
                                    <option value="entree_classe">Entrée Classe</option>
                                    <option value="sortie_classe">Sortie Classe</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Matière (si en classe)</label>
                                <select class="form-select" id="matiereSelect">
                                    <option value="">Sélectionner une matière</option>
                                    <!-- Rempli par JavaScript -->
                                </select>
                            </div>
                        </div>
                        
                        <!-- Statut du scanner -->
                        <div class="alert alert-info mt-4" id="scannerStatus">
                            <div class="d-flex align-items-center">
                                <div id="statusIndicator" class="status-warning"></div>
                                <div>
                                    <strong>Scanner en attente</strong><br>
                                    <small>Cliquez sur "Démarrer Scan" pour activer la caméra</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <!-- Résultat du scan -->
                <div class="card result-card" id="resultCard">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Résultat du Scan</h5>
                    </div>
                    <div class="card-body" id="resultContent">
                        <!-- Rempli par JavaScript -->
                    </div>
                </div>
                
                <!-- Historique des scans -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>
                            Historique Récent
                        </h5>
                    </div>
                    <div class="card-body" id="scanHistory" style="max-height: 300px; overflow-y: auto;">
                        <!-- Rempli par JavaScript -->
                    </div>
                </div>
                
                <!-- Statistiques rapides -->
                <div class="card mt-4">
                    <div class="card-body">
                        <h6><i class="fas fa-chart-bar me-2"></i>Statistiques du Jour</h6>
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="display-6 text-success" id="statsPresents">0</div>
                                <small>Présents</small>
                            </div>
                            <div class="col-6">
                                <div class="display-6 text-danger" id="statsAbsents">0</div>
                                <small>Absents</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Modal de saisie manuelle -->
        <div class="modal fade" id="manualEntryModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Saisie Manuelle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Matricule ou Nom de l'étudiant</label>
                            <input type="text" class="form-control" id="manualSearch" 
                                   placeholder="Rechercher...">
                            <div id="manualResults" class="mt-2" style="max-height: 200px; overflow-y: auto;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quagga@0.12.1/dist/quagga.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
    let scannerActive = false;
    let currentStream = null;
    let cameras = [];
    let currentCameraIndex = 0;
    let scanHistory = [];
    
    // Charger les matières
    async function loadMatieres() {
        try {
            const response = await fetch('ajax/get_matieres.php');
            const matieres = await response.json();
            
            const select = document.getElementById('matiereSelect');
            select.innerHTML = '<option value="">Sélectionner une matière</option>';
            
            matieres.forEach(matiere => {
                const option = document.createElement('option');
                option.value = matiere.id;
                option.textContent = matiere.nom;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Erreur chargement matières:', error);
        }
    }
    
    // Démarrer le scanner
    async function startScanner() {
        const video = document.getElementById('scanner');
        const overlay = document.getElementById('scanner-overlay');
        const startBtn = document.getElementById('startScanner');
        const stopBtn = document.getElementById('stopScanner');
        
        overlay.classList.remove('d-none');
        
        try {
            // Obtenir la liste des caméras
            const devices = await navigator.mediaDevices.enumerateDevices();
            cameras = devices.filter(device => device.kind === 'videoinput');
            
            if (cameras.length === 0) {
                throw new Error('Aucune caméra disponible');
            }
            
            // Démarrer le flux vidéo
            const constraints = {
                video: {
                    deviceId: cameras[currentCameraIndex].deviceId,
                    facingMode: 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                },
                audio: false
            };
            
            currentStream = await navigator.mediaDevices.getUserMedia(constraints);
            video.srcObject = currentStream;
            
            // Attendre que la vidéo soit prête
            await new Promise((resolve) => {
                video.onloadedmetadata = () => {
                    video.play();
                    resolve();
                };
            });
            
            // Initialiser QuaggaJS pour le scan QR
            Quagga.init({
                inputStream: {
                    name: "Live",
                    type: "LiveStream",
                    target: video,
                    constraints: constraints
                },
                decoder: {
                    readers: ["qr_code_reader"]
                },
                locate: true
            }, function(err) {
                if (err) {
                    console.error('Erreur Quagga:', err);
                    updateScannerStatus('error', 'Erreur d\'initialisation du scanner');
                    return;
                }
                
                Quagga.start();
                scannerActive = true;
                
                // Détecter les codes QR
                Quagga.onDetected(function(result) {
                    const code = result.codeResult.code;
                    processQRCode(code);
                });
            });
            
            // Mettre à jour l'UI
            overlay.classList.add('d-none');
            startBtn.disabled = true;
            stopBtn.disabled = false;
            updateScannerStatus('success', 'Scanner actif - Pointez vers un QR Code');
            
        } catch (error) {
            console.error('Erreur:', error);
            overlay.classList.add('d-none');
            updateScannerStatus('error', 'Erreur: ' + error.message);
            
            // Proposer la saisie manuelle
            Swal.fire({
                title: 'Caméra indisponible',
                text: 'Voulez-vous entrer le matricule manuellement ?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Oui',
                cancelButtonText: 'Non'
            }).then((result) => {
                if (result.isConfirmed) {
                    showManualEntry();
                }
            });
        }
    }
    
    // Arrêter le scanner
    function stopScanner() {
        if (scannerActive) {
            Quagga.stop();
            scannerActive = false;
        }
        
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
        
        const video = document.getElementById('scanner');
        video.srcObject = null;
        
        document.getElementById('startScanner').disabled = false;
        document.getElementById('stopScanner').disabled = true;
        updateScannerStatus('warning', 'Scanner arrêté');
    }
    
    // Changer de caméra
    function switchCamera() {
        if (cameras.length <= 1) return;
        
        currentCameraIndex = (currentCameraIndex + 1) % cameras.length;
        stopScanner();
        setTimeout(startScanner, 500);
    }
    
    // Traiter le QR code scanné
    async function processQRCode(code) {
        try {
            updateScannerStatus('info', 'Traitement du code...');
            
            // Extraire les données du QR code
            // Format attendu: ETUDIANT:MATRICULE|NOM:...|PRENOM:...|SITE:...
            const data = parseQRData(code);
            
            if (!data.matricule) {
                throw new Error('QR Code invalide');
            }
            
            // Envoyer les données au serveur
            const response = await fetch('ajax/process_qr_scan.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    qr_data: code,
                    matricule: data.matricule,
                    type_presence: document.getElementById('presenceType').value,
                    matiere_id: document.getElementById('matiereSelect').value || null
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Afficher le résultat
                showScanResult(result);
                
                // Ajouter à l'historique
                addToHistory({
                    type: 'success',
                    student: result.student,
                    time: new Date().toLocaleTimeString(),
                    message: 'Présence enregistrée'
                });
                
                // Mettre à jour les statistiques
                updateStats();
                
                // Son de succès
                playSuccessSound();
                
                // Réinitialiser après 3 secondes
                setTimeout(() => {
                    document.getElementById('resultCard').style.display = 'none';
                }, 3000);
                
            } else {
                throw new Error(result.message);
            }
            
        } catch (error) {
            updateScannerStatus('error', 'Erreur: ' + error.message);
            
            addToHistory({
                type: 'error',
                student: code.substring(0, 20) + '...',
                time: new Date().toLocaleTimeString(),
                message: error.message
            });
            
            playErrorSound();
        }
    }
    
    // Parser les données du QR code
    function parseQRData(code) {
        const data = {};
        const parts = code.split('|');
        
        parts.forEach(part => {
            const [key, value] = part.split(':');
            if (key && value) {
                data[key.toLowerCase()] = value;
            }
        });
        
        return data;
    }
    
    // Afficher le résultat du scan
    function showScanResult(result) {
        const resultCard = document.getElementById('resultCard');
        const resultContent = document.getElementById('resultContent');
        
        let html = `
            <div class="text-center">
                <div class="mb-3">
                    <i class="fas fa-check-circle fa-4x text-success"></i>
                </div>
                <h4 class="text-success">Présence Enregistrée !</h4>
                <p class="lead">${result.student.nom} ${result.student.prenom}</p>
                <div class="row">
                    <div class="col-6">
                        <small class="text-muted">Matricule</small><br>
                        <strong>${result.student.matricule}</strong>
                    </div>
                    <div class="col-6">
                        <small class="text-muted">Classe</small><br>
                        <strong>${result.student.classe || 'N/A'}</strong>
                    </div>
                </div>
                <div class="mt-3">
                    <span class="badge bg-info">${getPresenceTypeText(result.presence.type)}</span>
                    <span class="badge bg-success ms-2">${getStatutText(result.presence.statut)}</span>
                </div>
                <p class="mt-3 mb-0">
                    <i class="fas fa-clock me-1"></i>
                    ${new Date(result.presence.date_heure).toLocaleTimeString()}
                </p>
            </div>
        `;
        
        resultContent.innerHTML = html;
        resultCard.style.display = 'block';
        updateScannerStatus('success', 'Présence enregistrée avec succès');
    }
    
    // Ajouter à l'historique
    function addToHistory(item) {
        scanHistory.unshift(item);
        if (scanHistory.length > 10) scanHistory.pop();
        
        const historyDiv = document.getElementById('scanHistory');
        let html = '';
        
        scanHistory.forEach((item, index) => {
            const typeClass = `history-${item.type}`;
            html += `
                <div class="history-item ${typeClass}">
                    <div class="d-flex justify-content-between">
                        <strong>${item.student}</strong>
                        <small>${item.time}</small>
                    </div>
                    <small>${item.message}</small>
                </div>
            `;
        });
        
        historyDiv.innerHTML = html || '<p class="text-muted">Aucun scan récent</p>';
    }
    
    // Mettre à jour les statistiques
    async function updateStats() {
        try {
            const response = await fetch('ajax/get_today_stats.php');
            const stats = await response.json();
            
            document.getElementById('statsPresents').textContent = stats.presents || 0;
            document.getElementById('statsAbsents').textContent = stats.absents || 0;
        } catch (error) {
            console.error('Erreur stats:', error);
        }
    }
    
    // Mettre à jour le statut du scanner
    function updateScannerStatus(type, message) {
        const indicator = document.getElementById('statusIndicator');
        const statusDiv = document.getElementById('scannerStatus');
        
        indicator.className = `status-${type}`;
        statusDiv.innerHTML = `
            <div class="d-flex align-items-center">
                <div id="statusIndicator" class="status-${type}"></div>
                <div>
                    <strong>${getStatusTitle(type)}</strong><br>
                    <small>${message}</small>
                </div>
            </div>
        `;
    }
    
    function getStatusTitle(type) {
        switch(type) {
            case 'success': return 'Scanner actif';
            case 'error': return 'Erreur';
            case 'warning': return 'Scanner arrêté';
            case 'info': return 'Traitement';
            default: return 'Statut inconnu';
        }
    }
    
    function getPresenceTypeText(type) {
        const types = {
            'entree_ecole': 'Entrée École',
            'sortie_ecole': 'Sortie École',
            'entree_classe': 'Entrée Classe',
            'sortie_classe': 'Sortie Classe'
        };
        return types[type] || type;
    }
    
    function getStatutText(statut) {
        const statuts = {
            'present': 'Présent',
            'absent': 'Absent',
            'retard': 'En retard'
        };
        return statuts[statut] || statut;
    }
    
    // Sons
    function playSuccessSound() {
        const audio = new Audio('assets/sounds/success.mp3');
        audio.play().catch(() => {});
    }
    
    function playErrorSound() {
        const audio = new Audio('assets/sounds/error.mp3');
        audio.play().catch(() => {});
    }
    
    // Saisie manuelle
    function showManualEntry() {
        const modal = new bootstrap.Modal(document.getElementById('manualEntryModal'));
        modal.show();
    }
    
    // Importer image QR code
    function uploadImage() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        
        input.onchange = async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            
            try {
                // Lire le QR code depuis l'image
                // Note: Pour une implémentation complète, utiliser une bibliothèque comme jsQR
                const reader = new FileReader();
                reader.onload = async (event) => {
                    // TODO: Implémenter la lecture QR depuis image
                    // Pour l'instant, on simule
                    updateScannerStatus('info', 'Analyse de l\'image...');
                    
                    setTimeout(() => {
                        // Simuler un scan réussi
                        processQRCode('ETUDIANT:ISGI-2025-00001|NOM:DUPONT|PRENOM:Jean|SITE:1');
                    }, 1000);
                };
                reader.readAsDataURL(file);
            } catch (error) {
                updateScannerStatus('error', 'Erreur lors de la lecture de l\'image');
            }
        };
        
        input.click();
    }
    
    // Événements
    document.getElementById('startScanner').addEventListener('click', startScanner);
    document.getElementById('stopScanner').addEventListener('click', stopScanner);
    document.getElementById('switchCamera').addEventListener('click', switchCamera);
    document.getElementById('uploadImage').addEventListener('click', uploadImage);
    
    // Recherche manuelle
    document.getElementById('manualSearch').addEventListener('input', async (e) => {
        const query = e.target.value.trim();
        if (query.length < 2) return;
        
        try {
            const response = await fetch(`ajax/search_student.php?q=${encodeURIComponent(query)}`);
            const students = await response.json();
            
            const resultsDiv = document.getElementById('manualResults');
            resultsDiv.innerHTML = students.map(student => `
                <div class="card mb-2 clickable" onclick="selectStudent(${student.id})">
                    <div class="card-body py-2">
                        <strong>${student.matricule}</strong> - ${student.nom} ${student.prenom}<br>
                        <small class="text-muted">${student.classe || ''}</small>
                    </div>
                </div>
            `).join('');
        } catch (error) {
            console.error('Erreur recherche:', error);
        }
    });
    
    // Sélectionner un étudiant manuellement
    async function selectStudent(studentId) {
        try {
            const response = await fetch('ajax/process_manual_entry.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    student_id: studentId,
                    type_presence: document.getElementById('presenceType').value,
                    matiere_id: document.getElementById('matiereSelect').value || null
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showScanResult(result);
                bootstrap.Modal.getInstance(document.getElementById('manualEntryModal')).hide();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            Swal.fire('Erreur', error.message, 'error');
        }
    }
    
    // Initialisation
    document.addEventListener('DOMContentLoaded', () => {
        loadMatieres();
        updateStats();
        
        // Charger l'historique initial
        updateScannerStatus('warning', 'Cliquez sur "Démarrer Scan" pour activer la caméra');
        
        // Empêcher la fermeture de la page pendant le scan
        window.addEventListener('beforeunload', (e) => {
            if (scannerActive) {
                e.preventDefault();
                e.returnValue = 'Le scanner est toujours actif. Êtes-vous sûr de vouloir quitter ?';
            }
        });
    });
    </script>
</body>
</html>