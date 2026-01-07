<?php
// create_account_student_tutor.php - Version pour étudiants et tuteurs

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
$config = [
    'db_host' => 'localhost',
    'db_name' => 'isgi_systeme',
    'db_user' => 'root',
    'db_pass' => 'admin1234',
    'min_password_length' => 8
];

class StudentTutorAccountCreator {
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
            
            // Vérifier si le matricule existe dans la base
            $student_info = $this->verifyMatricule($data['matricule'], $data['role_id']);
            
            // Vérifier l'unicité de l'email
            $this->checkEmailUnique($data['email']);
            
            // Créer l'utilisateur
            $user_id = $this->createUser($data, $student_info);
            
            // Enregistrer dans les logs
            $this->logActivity($user_id, $data, $student_info);
            
            // Envoyer la réponse
            $this->sendSuccess($user_id, $student_info);
            
        } catch (Exception $e) {
            $this->sendError($e->getMessage());
        }
    }
    
    private function validateInput() {
        $required = ['role_id', 'matricule', 'email', 'mot_de_passe', 'confirm_password', 'nom', 'prenom'];
        
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Le champ '$field' est obligatoire");
            }
        }
        
        $data = [
            'role_id' => intval($_POST['role_id']),
            'matricule' => trim($_POST['matricule']),
            'email' => trim($_POST['email']),
            'mot_de_passe' => $_POST['mot_de_passe'],
            'confirm_password' => $_POST['confirm_password'],
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
        
        if ($data['mot_de_passe'] !== $data['confirm_password']) {
            throw new Exception('Les mots de passe ne correspondent pas');
        }
        
        // Validation rôle (seulement étudiant ou tuteur)
        if ($data['role_id'] !== 8 && $data['role_id'] !== 9) {
            throw new Exception('Seuls les étudiants et tuteurs peuvent créer un compte via ce formulaire');
        }
        
        return $data;
    }
    
    private function verifyMatricule($matricule, $role_id) {
        // Vérifier dans la table etudiants
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.nom, e.prenom, e.site_id, e.utilisateur_id
            FROM etudiants e
            WHERE e.matricule = ?
        ");
        $stmt->execute([$matricule]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Matricule non trouvé dans la base de données');
        }
        
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Vérifier si un compte existe déjà pour cet étudiant
        if ($student['utilisateur_id'] !== null) {
            throw new Exception('Un compte existe déjà pour cet étudiant');
        }
        
        // Pour les tuteurs, vérifier les informations de correspondance
        if ($role_id === 9) {
            // Ici, vous pourriez ajouter une vérification supplémentaire
            // Par exemple, vérifier que le tuteur est bien associé à cet étudiant
        }
        
        return $student;
    }
    
    private function checkEmailUnique($email) {
        $stmt = $this->pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            throw new Exception('Cette adresse email est déjà utilisée');
        }
    }
    
    private function createUser($data, $student_info) {
        $password_hash = password_hash($data['mot_de_passe'], PASSWORD_DEFAULT);
        $code_verification = sprintf('%06d', mt_rand(1, 999999));
        $code_expiration = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Pour les étudiants et tuteurs, le statut est toujours 'en_attente' initialement
        $statut = 'en_attente';
        
        $stmt = $this->pdo->prepare("
            INSERT INTO utilisateurs (
                role_id, site_id, email, mot_de_passe, nom, prenom, telephone,
                code_verification, code_expiration, statut, matricule_associe, date_creation
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $data['role_id'],
            $student_info['site_id'], // Site de l'étudiant
            $data['email'],
            $password_hash,
            $data['nom'],
            $data['prenom'],
            $data['telephone'],
            $code_verification,
            $code_expiration,
            $statut,
            $data['matricule'] // Stocker le matricule associé
        ]);
        
        $user_id = $this->pdo->lastInsertId();
        
        // Mettre à jour l'étudiant avec l'ID utilisateur
        if ($data['role_id'] === 8) { // Étudiant
            $this->updateStudentWithUserId($student_info['id'], $user_id);
        }
        
        return $user_id;
    }
    
    private function updateStudentWithUserId($student_id, $user_id) {
        $stmt = $this->pdo->prepare("
            UPDATE etudiants 
            SET utilisateur_id = ? 
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $student_id]);
    }
    
    private function logActivity($user_id, $data, $student_info) {
        $role_names = [
            8 => 'Étudiant',
            9 => 'Tuteur'
        ];
        
        $role_name = $role_names[$data['role_id']] ?? 'Inconnu';
        $details = "Demande de compte {$role_name}: {$data['nom']} {$data['prenom']} ({$data['email']}) - Matricule: {$data['matricule']}";
        
        $stmt = $this->pdo->prepare("
            INSERT INTO logs_activite (
                utilisateur_id, utilisateur_type, action, table_concernée,
                id_enregistrement, details, date_action
            ) VALUES (?, 'visiteur', 'demande_compte', 'utilisateurs', ?, ?, NOW())
        ");
        
        $stmt->execute([$user_id, $user_id, $details]);
    }
    
    private function sendSuccess($user_id, $student_info) {
        echo json_encode([
            'success' => true,
            'message' => 'Demande de compte envoyée avec succès. En attente de validation par un administrateur.',
            'user_id' => $user_id,
            'requires_approval' => true,
            'student_info' => [
                'nom' => $student_info['nom'],
                'prenom' => $student_info['prenom']
            ]
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
$creator = new StudentTutorAccountCreator($config);
$creator->handleRequest();
?>