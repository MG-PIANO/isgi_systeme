<?php
// create_account_api.php - Version améliorée

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
$config = [
    'db_host' => 'localhost',
    'db_name' => 'isgi_systeme',
    'db_user' => 'root',
    'db_pass' => 'admin1234', // À configurer
    'min_password_length' => 8
];

class AccountCreator {
    private $pdo;
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
        $this->connectDB();
    }
    
    private function connectDB() {
        try {
            $dsn = "mysql:host={$this->config['db_host']};dbname={$this->config['db_name']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $this->config['db_user'], $this->config['db_pass']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->sendError('Erreur de connexion à la base de données: ' . $e->getMessage());
        }
    }
    
    public function handleRequest() {
        // Gérer les requêtes OPTIONS (CORS)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
        
        // Vérifier la méthode
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError('Méthode non autorisée', 405);
        }
        
        // Traiter la création de compte
        $this->createAccount();
    }
    
    private function createAccount() {
        try {
            // Récupérer et valider les données
            $data = $this->validateInput();
            
            // Vérifier l'unicité de l'email
            $this->checkEmailUnique($data['email']);
            
            // Déterminer le statut
            $statut_info = $this->getStatusInfo($data['role_id']);
            
            // Créer l'utilisateur
            $user_id = $this->createUser($data, $statut_info['statut']);
            
            // Créer les enregistrements spécifiques selon le rôle
            $this->createRoleSpecificRecords($user_id, $data);
            
            // Enregistrer dans les logs
            $this->logActivity($user_id, $data);
            
            // Envoyer la réponse
            $this->sendSuccess($user_id, $statut_info['requires_approval']);
            
        } catch (Exception $e) {
            $this->sendError($e->getMessage());
        }
    }
    
    private function validateInput() {
        $required = ['role_id', 'site_id', 'email', 'mot_de_passe', 'nom', 'prenom'];
        
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Le champ '$field' est obligatoire");
            }
        }
        
        $data = [
            'role_id' => intval($_POST['role_id']),
            'site_id' => intval($_POST['site_id']),
            'email' => trim($_POST['email']),
            'mot_de_passe' => $_POST['mot_de_passe'],
            'nom' => trim($_POST['nom']),
            'prenom' => trim($_POST['prenom']),
            'telephone' => isset($_POST['telephone']) ? trim($_POST['telephone']) : null
        ];
        
        // Validation email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Adresse email invalide');
        }
        
        // Validation mot de passe
        if (strlen($data['mot_de_passe']) < $this->config['min_password_length']) {
            throw new Exception("Le mot de passe doit contenir au moins {$this->config['min_password_length']} caractères");
        }
        
        // Validation rôle
        if ($data['role_id'] < 1 || $data['role_id'] > 9) {
            throw new Exception('Rôle invalide');
        }
        
        // Validation site
        if ($data['site_id'] < 1 || $data['site_id'] > 3) {
            throw new Exception('Site invalide');
        }
        
        return $data;
    }
    
    private function checkEmailUnique($email) {
        $stmt = $this->pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            throw new Exception('Cette adresse email est déjà utilisée');
        }
    }
    
    private function getStatusInfo($role_id) {
        $status_map = [
            1 => ['statut' => 'en_attente', 'requires_approval' => true],
            2 => ['statut' => 'en_attente', 'requires_approval' => true],
            3 => ['statut' => 'en_attente', 'requires_approval' => true],
            4 => ['statut' => 'en_attente', 'requires_approval' => true],
            5 => ['statut' => 'en_attente', 'requires_approval' => true],
            6 => ['statut' => 'en_attente', 'requires_approval' => true],
            7 => ['statut' => 'en_attente', 'requires_approval' => true],
            8 => ['statut' => 'en_attente', 'requires_approval' => true],
            9 => ['statut' => 'en_attente', 'requires_approval' => true],
        ];
        
        return $status_map[$role_id] ?? ['statut' => 'en_attente', 'requires_approval' => true];
    }
    
    private function createUser($data, $statut) {
        $password_hash = password_hash($data['mot_de_passe'], PASSWORD_DEFAULT);
        $code_verification = sprintf('%06d', mt_rand(1, 999999));
        $code_expiration = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO utilisateurs (
                role_id, site_id, email, mot_de_passe, nom, prenom, telephone,
                code_verification, code_expiration, statut, date_creation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $data['role_id'],
            $data['site_id'],
            $data['email'],
            $password_hash,
            $data['nom'],
            $data['prenom'],
            $data['telephone'],
            $code_verification,
            $code_expiration,
            $statut
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    private function createRoleSpecificRecords($user_id, $data) {
        switch ($data['role_id']) {
            case 8: // Étudiant
                $this->createStudentRecord($user_id, $data);
                break;
                
            case 7: // Professeur
                $this->createTeacherRecord($user_id, $data);
                break;
                
            case 6: // Surveillant
                // Pourrait créer un enregistrement spécifique si nécessaire
                break;
        }
    }
    
    private function createStudentRecord($user_id, $data) {
        $matricule = 'ISGI-' . date('Y') . '-' . str_pad($user_id, 5, '0', STR_PAD_LEFT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO etudiants (
                utilisateur_id, site_id, matricule, nom, prenom, date_inscription, statut
            ) VALUES (?, ?, ?, ?, ?, NOW(), 'actif')
        ");
        
        $stmt->execute([
            $user_id,
            $data['site_id'],
            $matricule,
            $data['nom'],
            $data['prenom']
        ]);
    }
    
    private function createTeacherRecord($user_id, $data) {
        $matricule = 'ENS-' . str_pad($user_id, 3, '0', STR_PAD_LEFT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO enseignants (
                utilisateur_id, matricule, site_id, statut
            ) VALUES (?, ?, ?, 'actif')
        ");
        
        $stmt->execute([
            $user_id,
            $matricule,
            $data['site_id']
        ]);
    }
    
    private function logActivity($user_id, $data) {
        $role_names = [
            1 => 'Administrateur Principal',
            2 => 'Administrateur Site',
            3 => 'Gestionnaire Principal',
            4 => 'Gestionnaire Secondaire',
            5 => 'DAC',
            6 => 'Surveillant Général',
            7 => 'Professeur',
            8 => 'Étudiant',
            9 => 'Tuteur'
        ];
        
        $role_name = $role_names[$data['role_id']] ?? 'Inconnu';
        $details = "Nouveau compte: {$data['nom']} {$data['prenom']} ({$data['email']}) - Rôle: {$role_name}";
        
        $stmt = $this->pdo->prepare("
            INSERT INTO logs_activite (
                utilisateur_id, utilisateur_type, action, table_concernée,
                id_enregistrement, details, date_action
            ) VALUES (?, 'admin', 'nouvelle_inscription', 'utilisateurs', ?, ?, NOW())
        ");
        
        $stmt->execute([$user_id, $user_id, $details]);
    }
    
    private function sendSuccess($user_id, $requires_approval) {
        $message = $requires_approval 
            ? 'Compte créé avec succès. En attente de validation par un administrateur.'
            : 'Compte créé avec succès. Vous pouvez maintenant vous connecter.';
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'user_id' => $user_id,
            'requires_approval' => $requires_approval
        ]);
    }
    
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit();
    }
}

// Exécuter l'application
$creator = new AccountCreator($config);
$creator->handleRequest();
?>