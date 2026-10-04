<?php
// scanner_qr_complet.php - Fichier unique complet

// ==================== CONFIGURATION ====================
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Vérifier l'authentification
if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) != 6) {
    header('Location: login.php');
    exit();
}

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'isgi_systeme');
define('DB_USER', 'root');
define('DB_PASS', 'admin1234');

// Classe de connexion à la base de données
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Erreur de connexion: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// ==================== FONCTIONS AJAX ====================
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // Définir le type de contenu
    header('Content-Type: application/json');
    
    switch ($action) {
        case 'verify_student':
            verifyStudent();
            break;
        case 'save_presence':
            savePresence();
            break;
        case 'get_stats':
            getStats();
            break;
        case 'get_scanned':
            getScanned();
            break;
        case 'search_student':
            searchStudent();
            break;
        case 'get_student_details':
            getStudentDetails();
            break;
        case 'export_data':
            exportData();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
    }
    exit();
}

// ==================== PAGE PRINCIPALE ====================
$pageTitle = "Scanner QR - Système Complet";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Styles -->
    <style>
    :root {
        --primary: #2c3e50;
        --secondary: #3498db;
        --success: #27ae60;
        --warning: #f39c12;
        --danger: #e74c3c;
        --light: #ecf0f1;
        --dark: #2c3e50;
    }
    
    body {
        background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        min-height: 100vh;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
    }
    
    .navbar {
        background: rgba(44, 62, 80, 0.95);
        backdrop-filter: blur(10px);
        box-shadow: 0 2px 20px rgba(0,0,0,0.1);
    }
    
    .scanner-container {
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        overflow: hidden;
        margin: 20px auto;
        max-width: 1400px;
    }
    
    .scanner-view {
        width: 100%;
        height: 500px;
        background: #000;
        position: relative;
        overflow: hidden;
    }
    
    .scan-frame {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 280px;
        height: 280px;
        border: 3px solid var(--success);
        border-radius: 15px;
        box-shadow: 0 0 0 1000px rgba(0,0,0,0.6),
                    0 0 30px var(--success),
                    inset 0 0 20px rgba(39, 174, 96, 0.3);
        animation: pulse 2s infinite;
        z-index: 10;
    }
    
    @keyframes pulse {
        0%, 100% { border-color: var(--success); }
        50% { border-color: #2ecc71; }
    }
    
    .scan-line {
        position: absolute;
        top: 0;
        left: 10%;
        width: 80%;
        height: 3px;
        background: linear-gradient(90deg, 
            transparent, 
            var(--success), 
            #2ecc71,
            var(--success),
            transparent);
        animation: scan 2s linear infinite;
        z-index: 11;
    }
    
    @keyframes scan {
        0% { top: 0; opacity: 0; }
        10% { opacity: 1; }
        90% { opacity: 1; }
        100% { top: 100%; opacity: 0; }
    }
    
    .auto-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        background: linear-gradient(45deg, var(--success), #2ecc71);
        color: white;
        padding: 8px 15px;
        border-radius: 20px;
        font-weight: bold;
        font-size: 12px;
        z-index: 12;
        box-shadow: 0 4px 15px rgba(39, 174, 96, 0.4);
        animation: badge-pulse 1.5s infinite;
    }
    
    @keyframes badge-pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
    
    .student-card {
        background: white;
        border-radius: 15px;
        border: 1px solid var(--light);
        transition: all 0.3s ease;
        overflow: hidden;
        margin-bottom: 15px;
    }
    
    .student-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    
    .stats-card {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        color: white;
        border-radius: 15px;
        padding: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    }
    
    .stat-number {
        font-size: 36px;
        font-weight: bold;
        line-height: 1;
    }
    
    .table-responsive {
        max-height: 400px;
        overflow-y: auto;
        border-radius: 10px;
        border: 1px solid var(--light);
    }
    
    .table th {
        background: var(--primary);
        color: white;
        position: sticky;
        top: 0;
    }
    
    .badge-presence {
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 12px;
        font-weight: bold;
    }
    
    .badge-present { background: var(--success); color: white; }
    .badge-late { background: var(--warning); color: white; }
    .badge-absent { background: var(--danger); color: white; }
    
    .loading {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100px;
    }
    
    .spinner {
        width: 40px;
        height: 40px;
        border: 4px solid var(--light);
        border-top: 4px solid var(--secondary);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
    }
    
    .toast {
        background: white;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 10px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        border-left: 4px solid;
        animation: slideIn 0.3s ease;
    }
    
    .toast-success { border-color: var(--success); }
    .toast-error { border-color: var(--danger); }
    .toast-warning { border-color: var(--warning); }
    
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    .camera-selector {
        position: absolute;
        top: 15px;
        left: 15px;
        z-index: 12;
        background: rgba(0,0,0,0.7);
        border-radius: 10px;
        padding: 5px;
    }
    
    .control-btn {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        margin: 0 5px;
        transition: all 0.3s;
    }
    
    .control-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    
    .btn-success { background: var(--success); color: white; }
    .btn-warning { background: var(--warning); color: white; }
    .btn-danger { background: var(--danger); color: white; }
    .btn-primary { background: var(--secondary); color: white; }
    </style>
    
    <!-- Scripts -->
    <script>
    // Configuration globale
    const CONFIG = {
        AUTO_SAVE: true,
        AUTO_START: true,
        SOUND_ENABLED: true,
        VIBRATION_ENABLED: true,
        SCAN_DELAY: 1000 // 1 seconde entre les scans
    };
    
    // Scanner global
    let scanner = null;
    let scannerActive = false;
    let currentCamera = null;
    let cameras = [];
    let scannedStudents = [];
    let stats = { present: 0, late: 0, absent: 0, total: 0 };
    
    // Initialisation
    document.addEventListener('DOMContentLoaded', async function() {
        await initScanner();
        loadStats();
        loadScannedStudents();
        setupEventListeners();
    });
    
    // Initialiser le scanner
    async function initScanner() {
        try {
            // Charger la bibliothèque QR scanner
            if (typeof Html5Qrcode === 'undefined') {
                await loadScript('https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js');
            }
            
            scanner = new Html5Qrcode("scannerView");
            
            // Obtenir les caméras
            cameras = await Html5Qrcode.getCameras();
            
            if (cameras.length === 0) {
                showError('Aucune caméra disponible');
                return;
            }
            
            // Configurer la sélection de caméra
            setupCameraSelector();
            
            // Démarrer automatiquement si configuré
            if (CONFIG.AUTO_START) {
                await startScanner();
            }
            
        } catch (error) {
            console.error('Erreur initialisation:', error);
            showError('Erreur initialisation: ' + error.message);
        }
    }
    
    // Démarrer le scanner
    async function startScanner(cameraId = null) {
        if (scannerActive) return;
        
        try {
            if (!cameraId) {
                cameraId = cameras[0].id;
                currentCamera = cameraId;
            }
            
            await scanner.start(
                cameraId,
                {
                    fps: 30,
                    qrbox: { width: 250, height: 250 },
                    aspectRatio: 1.0
                },
                onScanSuccess,
                onScanError
            );
            
            scannerActive = true;
            updateControls();
            showToast('Scanner démarré', 'success');
            
            // Vibration
            if (CONFIG.VIBRATION_ENABLED && 'vibrate' in navigator) {
                navigator.vibrate(100);
            }
            
        } catch (error) {
            console.error('Erreur démarrage:', error);
            showError('Erreur démarrage: ' + error.message);
        }
    }
    
    // Arrêter le scanner
    async function stopScanner() {
        if (scanner && scannerActive) {
            await scanner.stop();
            scannerActive = false;
            updateControls();
            showToast('Scanner arrêté', 'warning');
        }
    }
    
    // Gérer la détection de QR code
    async function onScanSuccess(decodedText) {
        // Empêcher les scans trop rapides
        if (Date.now() - (window.lastScanTime || 0) < CONFIG.SCAN_DELAY) {
            return;
        }
        window.lastScanTime = Date.now();
        
        // Animation visuelle
        highlightScanner();
        
        // Son de scan
        if (CONFIG.SOUND_ENABLED) {
            playScanSound();
        }
        
        try {
            // Vérifier l'étudiant
            const student = await verifyStudent(decodedText);
            
            if (!student) {
                throw new Error('Étudiant non trouvé');
            }
            
            // Si auto-save activé, enregistrer automatiquement
            if (CONFIG.AUTO_SAVE) {
                await autoSavePresence(student);
            } else {
                // Sinon, demander confirmation
                showVerificationModal(student);
            }
            
        } catch (error) {
            console.error('Erreur scan:', error);
            showToast('Erreur: ' + error.message, 'error');
        }
    }
    
    // Vérifier un étudiant
    async function verifyStudent(qrData) {
        try {
            const response = await fetch('?action=verify_student', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ qr_data: qrData })
            });
            
            const result = await response.json();
            
            if (result.success) {
                return result.student;
            } else {
                throw new Error(result.message);
            }
            
        } catch (error) {
            throw new Error('Erreur vérification: ' + error.message);
        }
    }
    
    // Enregistrement automatique
    async function autoSavePresence(student) {
        try {
            const response = await fetch('?action=save_presence', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    student_id: student.id,
                    matricule: student.matricule,
                    auto_save: true
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showToast('Présence enregistrée: ' + student.nom, 'success');
                
                // Mettre à jour l'interface
                addToScannedTable(result.student, result.presence);
                updateStats(result.stats);
                
                // Son de succès
                if (CONFIG.SOUND_ENABLED) {
                    playSuccessSound();
                }
                
                // Vibration
                if (CONFIG.VIBRATION_ENABLED && 'vibrate' in navigator) {
                    navigator.vibrate(200);
                }
                
            } else {
                throw new Error(result.message);
            }
            
        } catch (error) {
            showToast('Erreur: ' + error.message, 'error');
        }
    }
    
    // Charger les statistiques
    async function loadStats() {
        try {
            const response = await fetch('?action=get_stats');
            const result = await response.json();
            
            if (result.success) {
                stats = result.stats;
                updateStatsDisplay();
            }
        } catch (error) {
            console.error('Erreur chargement stats:', error);
        }
    }
    
    // Charger les étudiants scannés
    async function loadScannedStudents() {
        try {
            const response = await fetch('?action=get_scanned');
            const result = await response.json();
            
            if (result.success) {
                scannedStudents = result.students;
                updateScannedTable();
            }
        } catch (error) {
            console.error('Erreur chargement étudiants:', error);
        }
    }
    
    // Mettre à jour l'affichage des stats
    function updateStatsDisplay() {
        document.getElementById('statsPresent').textContent = stats.present || 0;
        document.getElementById('statsLate').textContent = stats.late || 0;
        document.getElementById('statsAbsent').textContent = stats.absent || 0;
        document.getElementById('statsTotal').textContent = stats.total || 0;
        
        const progress = document.getElementById('attendanceProgress');
        const percent = stats.total > 0 ? Math.round((stats.present / stats.total) * 100) : 0;
        progress.style.width = percent + '%';
        progress.textContent = percent + '%';
    }
    
    // Ajouter à la table des scannés
    function addToScannedTable(student, presence) {
        const tbody = document.getElementById('scannedTableBody');
        const row = document.createElement('tr');
        
        row.innerHTML = `
            <td>${tbody.children.length + 1}</td>
            <td><strong>${student.matricule}</strong></td>
            <td>${student.nom} ${student.prenom}</td>
            <td>${student.classe || ''}</td>
            <td>${getPresenceTypeText(presence.type_presence)}</td>
            <td>${new Date(presence.date_heure).toLocaleTimeString()}</td>
            <td><span class="badge-presence badge-${presence.statut}">${getStatutText(presence.statut)}</span></td>
            <td>
                <button class="btn btn-sm btn-outline-info" onclick="viewStudentDetails(${student.id})">
                    <i class="fas fa-eye"></i>
                </button>
            </td>
        `;
        
        tbody.insertBefore(row, tbody.firstChild);
        
        // Limiter à 50 lignes
        if (tbody.children.length > 50) {
            tbody.removeChild(tbody.lastChild);
        }
        
        // Mettre à jour le compteur
        document.getElementById('scannedCount').textContent = tbody.children.length;
    }
    
    // Mettre à jour la table complète
    function updateScannedTable() {
        const tbody = document.getElementById('scannedTableBody');
        tbody.innerHTML = '';
        
        scannedStudents.forEach((item, index) => {
            addToScannedTable(item.student, item.presence);
        });
    }
    
    // Mettre à jour les statistiques
    function updateStats(newStats) {
        stats = { ...stats, ...newStats };
        updateStatsDisplay();
    }
    
    // Configurer le sélecteur de caméra
    function setupCameraSelector() {
        const selector = document.getElementById('cameraSelect');
        if (!selector) return;
        
        selector.innerHTML = '';
        cameras.forEach((camera, index) => {
            const option = document.createElement('option');
            option.value = camera.id;
            option.textContent = camera.label || `Caméra ${index + 1}`;
            selector.appendChild(option);
        });
        
        selector.addEventListener('change', async function() {
            if (this.value && scannerActive) {
                await stopScanner();
                await startScanner(this.value);
            }
        });
    }
    
    // Mettre à jour les contrôles
    function updateControls() {
        const startBtn = document.getElementById('startScanner');
        const stopBtn = document.getElementById('stopScanner');
        
        if (scannerActive) {
            startBtn.disabled = true;
            stopBtn.disabled = false;
            document.getElementById('autoBadge').style.display = 'block';
        } else {
            startBtn.disabled = false;
            stopBtn.disabled = true;
            document.getElementById('autoBadge').style.display = 'none';
        }
    }
    
    // Configurer les événements
    function setupEventListeners() {
        // Contrôles scanner
        document.getElementById('startScanner').addEventListener('click', startScanner);
        document.getElementById('stopScanner').addEventListener('click', stopScanner);
        
        // Recherche manuelle
        document.getElementById('searchStudent').addEventListener('input', function(e) {
            searchStudents(e.target.value);
        });
        
        // Export
        document.getElementById('exportBtn').addEventListener('click', exportData);
        
        // Paramètres
        document.getElementById('autoSaveToggle').addEventListener('change', function(e) {
            CONFIG.AUTO_SAVE = e.target.checked;
            localStorage.setItem('scannerConfig', JSON.stringify(CONFIG));
        });
        
        document.getElementById('soundToggle').addEventListener('change', function(e) {
            CONFIG.SOUND_ENABLED = e.target.checked;
            localStorage.setItem('scannerConfig', JSON.stringify(CONFIG));
        });
        
        // Charger la configuration sauvegardée
        const savedConfig = localStorage.getItem('scannerConfig');
        if (savedConfig) {
            Object.assign(CONFIG, JSON.parse(savedConfig));
            document.getElementById('autoSaveToggle').checked = CONFIG.AUTO_SAVE;
            document.getElementById('soundToggle').checked = CONFIG.SOUND_ENABLED;
        }
    }
    
    // Rechercher des étudiants
    async function searchStudents(query) {
        if (query.length < 2) {
            document.getElementById('searchResults').innerHTML = '';
            return;
        }
        
        try {
            const response = await fetch(`?action=search_student&q=${encodeURIComponent(query)}`);
            const students = await response.json();
            
            const resultsDiv = document.getElementById('searchResults');
            resultsDiv.innerHTML = '';
            
            students.forEach(student => {
                const div = document.createElement('div');
                div.className = 'student-card p-3 mb-2';
                div.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">${student.nom} ${student.prenom}</h6>
                            <small class="text-muted">${student.matricule}</small>
                        </div>
                        <button class="btn btn-sm btn-primary" onclick="selectStudent(${student.id})">
                            <i class="fas fa-check"></i>
                        </button>
                    </div>
                `;
                resultsDiv.appendChild(div);
            });
            
        } catch (error) {
            console.error('Erreur recherche:', error);
        }
    }
    
    // Sélectionner un étudiant manuellement
    async function selectStudent(studentId) {
        try {
            const response = await fetch(`?action=get_student_details&id=${studentId}`);
            const student = await response.json();
            
            showVerificationModal(student);
            
        } catch (error) {
            showToast('Erreur: ' + error.message, 'error');
        }
    }
    
    // Afficher la modal de vérification
    function showVerificationModal(student) {
        // Implémenter la modal de vérification
        console.log('Vérification étudiante:', student);
        // Pour une implémentation complète, utiliser SweetAlert2 ou Bootstrap Modal
    }
    
    // Voir les détails d'un étudiant
    async function viewStudentDetails(studentId) {
        try {
            const response = await fetch(`?action=get_student_details&id=${studentId}`);
            const student = await response.json();
            
            // Afficher les détails dans une modal
            const modal = new bootstrap.Modal(document.getElementById('studentModal'));
            document.getElementById('studentModalContent').innerHTML = `
                <div class="modal-body">
                    <h4>${student.nom} ${student.prenom}</h4>
                    <p><strong>Matricule:</strong> ${student.matricule}</p>
                    <p><strong>Classe:</strong> ${student.classe || 'Non spécifiée'}</p>
                    <p><strong>Téléphone:</strong> ${student.telephone || 'Non spécifié'}</p>
                    <p><strong>Email:</strong> ${student.email || 'Non spécifié'}</p>
                </div>
            `;
            modal.show();
            
        } catch (error) {
            showToast('Erreur: ' + error.message, 'error');
        }
    }
    
    // Exporter les données
    async function exportData() {
        try {
            const response = await fetch('?action=export_data');
            const blob = await response.blob();
            
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `presences_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
        } catch (error) {
            showToast('Erreur export: ' + error.message, 'error');
        }
    }
    
    // Sons
    function playScanSound() {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        
        gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
        
        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.1);
    }
    
    function playSuccessSound() {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        
        // Premier bip
        setTimeout(() => {
            const oscillator1 = audioContext.createOscillator();
            const gainNode1 = audioContext.createGain();
            
            oscillator1.connect(gainNode1);
            gainNode1.connect(audioContext.destination);
            
            oscillator1.frequency.value = 1000;
            oscillator1.type = 'sine';
            
            gainNode1.gain.setValueAtTime(0.2, audioContext.currentTime);
            gainNode1.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
            
            oscillator1.start(audioContext.currentTime);
            oscillator1.stop(audioContext.currentTime + 0.1);
        }, 0);
        
        // Deuxième bip
        setTimeout(() => {
            const oscillator2 = audioContext.createOscillator();
            const gainNode2 = audioContext.createGain();
            
            oscillator2.connect(gainNode2);
            gainNode2.connect(audioContext.destination);
            
            oscillator2.frequency.value = 1200;
            oscillator2.type = 'sine';
            
            gainNode2.gain.setValueAtTime(0.2, audioContext.currentTime);
            gainNode2.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
            
            oscillator2.start(audioContext.currentTime);
            oscillator2.stop(audioContext.currentTime + 0.1);
        }, 100);
    }
    
    // Utilitaires
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
            'retard': 'Retard',
            'absent': 'Absent'
        };
        return statuts[statut] || statut;
    }
    
    function highlightScanner() {
        const scannerView = document.getElementById('scannerView');
        scannerView.style.boxShadow = 'inset 0 0 50px rgba(39, 174, 96, 0.5)';
        
        setTimeout(() => {
            scannerView.style.boxShadow = '';
        }, 500);
    }
    
    function showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer') || createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong>${type === 'success' ? '✓' : type === 'error' ? '✗' : 'ℹ'}</strong>
                    <span class="ms-2">${message}</span>
                </div>
                <button class="btn btn-sm" onclick="this.parentElement.parentElement.remove()">&times;</button>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Supprimer automatiquement après 3 secondes
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3000);
    }
    
    function createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
        return container;
    }
    
    function showError(title, message) {
        showToast(message, 'error');
    }
    
    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }
    
    // Gérer les erreurs de scan
    function onScanError(error) {
        // Ignorer les erreurs normales (pas de QR code)
        console.debug('Scan error:', error);
    }
    
    // Gérer la fermeture de la page
    window.addEventListener('beforeunload', (e) => {
        if (scannerActive) {
            e.preventDefault();
            e.returnValue = 'Le scanner est toujours actif. Voulez-vous vraiment quitter ?';
            return e.returnValue;
        }
    });
    </script>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-qrcode me-2"></i>
                Scanner QR Étudiants
            </a>
            <div class="d-flex align-items-center text-white">
                <div class="me-3">
                    <i class="fas fa-user me-1"></i>
                    <?php 
                    try {
                        $db = Database::getInstance();
                        $pdo = $db->getConnection();
                        $stmt = $pdo->prepare("SELECT nom FROM utilisateurs WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $user = $stmt->fetch();
                        echo htmlspecialchars($user['nom'] ?? 'Surveillant');
                    } catch (Exception $e) {
                        echo 'Surveillant';
                    }
                    ?>
                </div>
                <button class="btn btn-outline-light btn-sm" onclick="exportData()">
                    <i class="fas fa-download me-1"></i>Exporter
                </button>
            </div>
        </div>
    </nav>
    
    <!-- Contenu Principal -->
    <div class="container-fluid py-4">
        <div class="row">
            <!-- Colonne Scanner -->
            <div class="col-lg-8">
                <div class="scanner-container p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="mb-0">
                            <i class="fas fa-camera me-2"></i>
                            Scanner Automatique
                        </h4>
                        <div class="d-flex align-items-center">
                            <div class="form-check form-switch me-3">
                                <input class="form-check-input" type="checkbox" id="autoSaveToggle" checked>
                                <label class="form-check-label" for="autoSaveToggle">
                                    <i class="fas fa-save me-1"></i> Auto-save
                                </label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="soundToggle" checked>
                                <label class="form-check-label" for="soundToggle">
                                    <i class="fas fa-volume-up me-1"></i> Son
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Zone Scanner -->
                    <div class="scanner-view" id="scannerView">
                        <div class="scan-frame"></div>
                        <div class="scan-line"></div>
                        <div class="auto-badge" id="autoBadge" style="display: none;">
                            <i class="fas fa-robot me-1"></i> AUTO-SCAN
                        </div>
                        
                        <div class="camera-selector">
                            <select class="form-select form-select-sm" id="cameraSelect">
                                <option value="">Chargement caméras...</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Contrôles -->
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="d-flex justify-content-center">
                                <button class="control-btn btn-success" id="startScanner">
                                    <i class="fas fa-play"></i>
                                </button>
                                <button class="control-btn btn-danger" id="stopScanner" disabled>
                                    <i class="fas fa-stop"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-center">
                                <button class="control-btn btn-primary" onclick="document.getElementById('manualModal').style.display='block'">
                                    <i class="fas fa-keyboard"></i>
                                </button>
                                <button class="control-btn btn-warning" onclick="document.getElementById('uploadQR').click()">
                                    <i class="fas fa-upload"></i>
                                </button>
                            </div>
                            <input type="file" id="uploadQR" accept="image/*" style="display: none;" 
                                   onchange="uploadQRImage(this.files[0])">
                        </div>
                    </div>
                </div>
                
                <!-- Liste des étudiants scannés -->
                <div class="scanner-container mt-4 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="fas fa-list-check me-2"></i>
                            Étudiants Scannés <span class="badge bg-primary" id="scannedCount">0</span>
                        </h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="loadScannedStudents()">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Matricule</th>
                                    <th>Nom & Prénom</th>
                                    <th>Classe</th>
                                    <th>Type</th>
                                    <th>Heure</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="scannedTableBody">
                                <!-- Rempli dynamiquement -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Colonne Statistiques & Recherche -->
            <div class="col-lg-4">
                <!-- Statistiques -->
                <div class="stats-card">
                    <h5 class="mb-3">
                        <i class="fas fa-chart-bar me-2"></i>
                        Statistiques du Jour
                    </h5>
                    
                    <div class="row text-center mb-3">
                        <div class="col-3">
                            <div class="stat-number text-success" id="statsPresent">0</div>
                            <small>Présents</small>
                        </div>
                        <div class="col-3">
                            <div class="stat-number text-warning" id="statsLate">0</div>
                            <small>Retards</small>
                        </div>
                        <div class="col-3">
                            <div class="stat-number text-danger" id="statsAbsent">0</div>
                            <small>Absents</small>
                        </div>
                        <div class="col-3">
                            <div class="stat-number text-primary" id="statsTotal">0</div>
                            <small>Total</small>
                        </div>
                    </div>
                    
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-success" id="attendanceProgress" 
                             style="width: 0%">0%</div>
                    </div>
                    <small class="text-white-50">Taux de présence</small>
                </div>
                
                <!-- Recherche Manuelle -->
                <div class="scanner-container mt-4 p-4">
                    <h5 class="mb-3">
                        <i class="fas fa-search me-2"></i>
                        Recherche Manuelle
                    </h5>
                    
                    <div class="mb-3">
                        <input type="text" class="form-control" id="searchStudent" 
                               placeholder="Matricule, nom ou prénom...">
                    </div>
                    
                    <div id="searchResults" style="max-height: 300px; overflow-y: auto;">
                        <!-- Résultats de recherche -->
                    </div>
                </div>
                
                <!-- Dernières notifications -->
                <div class="scanner-container mt-4 p-4">
                    <h5 class="mb-3">
                        <i class="fas fa-bell me-2"></i>
                        Dernières Activités
                    </h5>
                    <div id="recentActivities"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Saisie Manuelle -->
    <div id="manualModal" class="modal" style="display: none;">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Saisie Manuelle</h5>
                    <button type="button" class="btn-close" onclick="document.getElementById('manualModal').style.display='none'"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label>Matricule</label>
                        <input type="text" class="form-control" id="manualMatricule">
                    </div>
                    <div class="mb-3">
                        <label>Type de présence</label>
                        <select class="form-select" id="manualPresenceType">
                            <option value="entree_ecole">Entrée École</option>
                            <option value="entree_classe">Entrée Classe</option>
                            <option value="sortie_classe">Sortie Classe</option>
                            <option value="sortie_ecole">Sortie École</option>
                        </select>
                    </div>
                    <button class="btn btn-success w-100" onclick="saveManualEntry()">
                        <i class="fas fa-save me-2"></i>Enregistrer
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Détails Étudiant -->
    <div class="modal fade" id="studentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Détails Étudiant</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="studentModalContent">
                    <!-- Rempli dynamiquement -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap Modal JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Fonction pour uploader une image QR
    async function uploadQRImage(file) {
        if (!file) return;
        
        try {
            showToast('Analyse de l\'image...', 'info');
            
            if (scanner) {
                const decodedText = await scanner.scanFile(file, true);
                
                if (decodedText) {
                    await onScanSuccess(decodedText);
                } else {
                    throw new Error('Aucun QR code trouvé');
                }
            }
            
        } catch (error) {
            showToast('Erreur: ' + error.message, 'error');
        }
    }
    
    // Fonction pour enregistrer une entrée manuelle
    async function saveManualEntry() {
        const matricule = document.getElementById('manualMatricule').value;
        const type = document.getElementById('manualPresenceType').value;
        
        if (!matricule) {
            showToast('Veuillez saisir un matricule', 'error');
            return;
        }
        
        try {
            const response = await fetch('?action=save_presence', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    matricule: matricule,
                    type_presence: type,
                    manual_entry: true
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showToast('Entrée manuelle enregistrée', 'success');
                document.getElementById('manualModal').style.display = 'none';
                document.getElementById('manualMatricule').value = '';
                
                // Mettre à jour l'interface
                loadScannedStudents();
                loadStats();
                
            } else {
                throw new Error(result.message);
            }
            
        } catch (error) {
            showToast('Erreur: ' + error.message, 'error');
        }
    }
    </script>
</body>
</html>

<?php
// ==================== IMPLÉMENTATION DES FONCTIONS AJAX ====================

function verifyStudent() {
    $data = json_decode(file_get_contents('php://input'), true);
    $qrData = $data['qr_data'] ?? '';
    
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        // Extraire le matricule du QR code
        $matricule = extractMatricule($qrData);
        
        if (empty($matricule)) {
            throw new Exception('Matricule non trouvé dans le QR code');
        }
        
        // VÉRIFICATION DANS LA TABLE ETUDIANT
        $student = getStudentByMatricule($pdo, $matricule);
        
        if (!$student) {
            throw new Exception('Étudiant non trouvé dans la base de données');
        }
        
        // Vérifier le statut
        if ($student['statut'] !== 'actif') {
            throw new Exception('Étudiant non actif. Statut: ' . $student['statut']);
        }
        
        echo json_encode([
            'success' => true,
            'student' => [
                'id' => $student['id'],
                'matricule' => $student['matricule'],
                'nom' => $student['nom'],
                'prenom' => $student['prenom'],
                'classe' => $student['classe_nom'] ?? '',
                'statut' => $student['statut']
            ],
            'message' => 'Étudiant vérifié avec succès'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function savePresence() {
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        // Récupérer les données
        $studentId = $data['student_id'] ?? null;
        $matricule = $data['matricule'] ?? '';
        $typePresence = $data['type_presence'] ?? 'entree_ecole';
        $manualEntry = $data['manual_entry'] ?? false;
        
        // Si manuel, chercher l'étudiant par matricule
        if ($manualEntry && !$studentId) {
            $student = getStudentByMatricule($pdo, $matricule);
            if (!$student) {
                throw new Exception('Étudiant non trouvé');
            }
            $studentId = $student['id'];
        }
        
        // Vérifier que l'étudiant existe
        $student = getStudentById($pdo, $studentId);
        if (!$student) {
            throw new Exception('Étudiant non trouvé');
        }
        
        // Déterminer le statut
        $dateHeure = date('Y-m-d H:i:s');
        $statut = 'present';
        
        // Vérifier les retards pour les entrées
        if (in_array($typePresence, ['entree_ecole', 'entree_classe'])) {
            $heureLimite = strtotime(date('Y-m-d 08:30:00'));
            $heureScan = strtotime($dateHeure);
            
            if ($heureScan > $heureLimite) {
                $statut = 'retard';
            }
        }
        
        // Vérifier les doublons
        if (isDuplicatePresence($pdo, $studentId, $typePresence)) {
            throw new Exception('Présence déjà enregistrée pour ce type aujourd\'hui');
        }
        
        // Enregistrer la présence
        $sql = "INSERT INTO presences 
                (etudiant_id, type_presence, date_heure, statut, surveillant_id) 
                VALUES (:etudiant_id, :type_presence, :date_heure, :statut, :surveillant_id)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':etudiant_id' => $studentId,
            ':type_presence' => $typePresence,
            ':date_heure' => $dateHeure,
            ':statut' => $statut,
            ':surveillant_id' => $_SESSION['user_id']
        ]);
        
        $presenceId = $pdo->lastInsertId();
        
        // Récupérer les stats
        $stats = getTodayStats($pdo);
        
        echo json_encode([
            'success' => true,
            'presence_id' => $presenceId,
            'student' => [
                'id' => $student['id'],
                'matricule' => $student['matricule'],
                'nom' => $student['nom'],
                'prenom' => $student['prenom'],
                'classe' => $student['classe_nom'] ?? ''
            ],
            'presence' => [
                'type_presence' => $typePresence,
                'statut' => $statut,
                'date_heure' => $dateHeure
            ],
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function getStats() {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $stats = getTodayStats($pdo);
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function getScanned() {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $today = date('Y-m-d');
        $surveillantId = $_SESSION['user_id'];
        
        $sql = "SELECT 
                    p.id as presence_id,
                    p.type_presence,
                    p.statut,
                    p.date_heure,
                    e.id as student_id,
                    e.matricule,
                    e.nom,
                    e.prenom,
                    c.nom as classe_nom
                FROM presences p
                JOIN etudiants e ON p.etudiant_id = e.id
                LEFT JOIN classes c ON e.classe_id = c.id
                WHERE p.surveillant_id = :surveillant_id
                AND DATE(p.date_heure) = :today
                ORDER BY p.date_heure DESC
                LIMIT 50";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':surveillant_id' => $surveillantId,
            ':today' => $today
        ]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $students = array_map(function($row) {
            return [
                'student' => [
                    'id' => $row['student_id'],
                    'matricule' => $row['matricule'],
                    'nom' => $row['nom'],
                    'prenom' => $row['prenom'],
                    'classe' => $row['classe_nom']
                ],
                'presence' => [
                    'type_presence' => $row['type_presence'],
                    'statut' => $row['statut'],
                    'date_heure' => $row['date_heure']
                ]
            ];
        }, $results);
        
        echo json_encode([
            'success' => true,
            'students' => $students
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

function searchStudent() {
    $query = $_GET['q'] ?? '';
    
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $sql = "SELECT 
                    e.id,
                    e.matricule,
                    e.nom,
                    e.prenom,
                    c.nom as classe_nom
                FROM etudiants e
                LEFT JOIN classes c ON e.classe_id = c.id
                WHERE e.statut = 'actif'
                AND (e.matricule LIKE :query 
                     OR e.nom LIKE :query 
                     OR e.prenom LIKE :query
                     OR CONCAT(e.nom, ' ', e.prenom) LIKE :query)
                ORDER BY e.nom, e.prenom
                LIMIT 10";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':query' => "%$query%"]);
        
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($students);
        
    } catch (Exception $e) {
        echo json_encode([]);
    }
}

function getStudentDetails() {
    $studentId = $_GET['id'] ?? 0;
    
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $sql = "SELECT 
                    e.*,
                    c.nom as classe_nom
                FROM etudiants e
                LEFT JOIN classes c ON e.classe_id = c.id
                WHERE e.id = :id
                AND e.deleted_at IS NULL";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $studentId]);
        
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception('Étudiant non trouvé');
        }
        
        echo json_encode($student);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function exportData() {
    try {
        $db = Database::getInstance();
        $pdo = $db->getConnection();
        
        $today = date('Y-m-d');
        $surveillantId = $_SESSION['user_id'];
        
        $sql = "SELECT 
                    e.matricule,
                    e.nom,
                    e.prenom,
                    c.nom as classe,
                    p.type_presence,
                    p.statut,
                    p.date_heure
                FROM presences p
                JOIN etudiants e ON p.etudiant_id = e.id
                LEFT JOIN classes c ON e.classe_id = c.id
                WHERE p.surveillant_id = :surveillant_id
                AND DATE(p.date_heure) = :today
                ORDER BY p.date_heure DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':surveillant_id' => $surveillantId,
            ':today' => $today
        ]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Générer CSV
        $output = fopen('php://output', 'w');
        
        // En-têtes
        fputcsv($output, [
            'Matricule', 'Nom', 'Prénom', 'Classe', 
            'Type de présence', 'Statut', 'Date et heure'
        ]);
        
        // Données
        foreach ($results as $row) {
            fputcsv($output, [
                $row['matricule'],
                $row['nom'],
                $row['prenom'],
                $row['classe'],
                getPresenceTypeText($row['type_presence']),
                getStatutText($row['statut']),
                $row['date_heure']
            ]);
        }
        
        fclose($output);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="presences_' . $today . '.csv"');
        
    } catch (Exception $e) {
        echo "Erreur: " . $e->getMessage();
    }
}

// ==================== FONCTIONS UTILITAIRES ====================

function extractMatricule($qrData) {
    if (is_array($qrData)) {
        return $qrData['matricule'] ?? $qrData['MATRICULE'] ?? '';
    }
    
    if (is_string($qrData)) {
        // Chercher le matricule
        if (preg_match('/MATRICULE[:\s]*([A-Z0-9\-]+)/i', $qrData, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/(ISGI|ETU|STD)[\-_\s]*[0-9]+/i', $qrData, $matches)) {
            return $matches[0];
        }
        
        if (preg_match('/^[A-Z0-9\-]{5,20}$/', $qrData)) {
            return $qrData;
        }
    }
    
    return '';
}

function getStudentByMatricule($pdo, $matricule) {
    $sql = "SELECT 
                e.*,
                c.nom as classe_nom
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            WHERE e.matricule = :matricule
            AND e.deleted_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':matricule' => $matricule]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getStudentById($pdo, $studentId) {
    $sql = "SELECT 
                e.*,
                c.nom as classe_nom
            FROM etudiants e
            LEFT JOIN classes c ON e.classe_id = c.id
            WHERE e.id = :id
            AND e.deleted_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $studentId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function isDuplicatePresence($pdo, $studentId, $typePresence) {
    $today = date('Y-m-d');
    
    $sql = "SELECT COUNT(*) as count 
            FROM presences 
            WHERE etudiant_id = :student_id 
            AND type_presence = :type_presence
            AND DATE(date_heure) = :today";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':student_id' => $studentId,
        ':type_presence' => $typePresence,
        ':today' => $today
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'] > 0;
}

function getTodayStats($pdo) {
    $today = date('Y-m-d');
    $surveillantId = $_SESSION['user_id'];
    
    $sql = "SELECT 
                COUNT(CASE WHEN statut = 'present' THEN 1 END) as present,
                COUNT(CASE WHEN statut = 'retard' THEN 1 END) as late,
                COUNT(CASE WHEN statut = 'absent' THEN 1 END) as absent,
                COUNT(*) as total
            FROM presences 
            WHERE surveillant_id = :surveillant_id
            AND DATE(date_heure) = :today";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':surveillant_id' => $surveillantId,
        ':today' => $today
    ]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getPresenceTypeText($type) {
    $types = [
        'entree_ecole' => 'Entrée École',
        'sortie_ecole' => 'Sortie École',
        'entree_classe' => 'Entrée Classe',
        'sortie_classe' => 'Sortie Classe'
    ];
    return $types[$type] ?? $type;
}

function getStatutText($statut) {
    $statuts = [
        'present' => 'Présent',
        'retard' => 'En retard',
        'absent' => 'Absent'
    ];
    return $statuts[$statut] ?? $statut;
}
?>