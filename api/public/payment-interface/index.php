<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement en ligne - ISGI Congo</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .payment-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }
        
        .payment-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .payment-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .payment-header p {
            opacity: 0.9;
            font-size: 16px;
        }
        
        .payment-body {
            padding: 40px;
        }
        
        .payment-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .info-label {
            color: #6c757d;
            font-weight: 500;
        }
        
        .info-value {
            font-weight: 600;
            color: #212529;
        }
        
        .payment-form {
            margin-top: 20px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-control.error {
            border-color: #dc3545;
        }
        
        .error-message {
            color: #dc3545;
            font-size: 14px;
            margin-top: 5px;
            display: none;
        }
        
        .operator-select {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .operator-option {
            flex: 1;
            text-align: center;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .operator-option:hover {
            border-color: #667eea;
        }
        
        .operator-option.active {
            border-color: #667eea;
            background: #f0f7ff;
        }
        
        .operator-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .btn-pay {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-pay:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .payment-status {
            text-align: center;
            padding: 30px;
            display: none;
        }
        
        .status-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }
        
        .status-success .status-icon {
            color: #28a745;
        }
        
        .status-error .status-icon {
            color: #dc3545;
        }
        
        .status-loading .status-icon {
            color: #007bff;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .qr-code-container {
            text-align: center;
            margin: 20px 0;
        }
        
        .qr-code {
            max-width: 200px;
            margin: 0 auto;
        }
        
        .countdown {
            font-size: 14px;
            color: #6c757d;
            margin-top: 10px;
        }
        
        .loader {
            display: inline-block;
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @media (max-width: 576px) {
            .payment-container {
                border-radius: 15px;
            }
            
            .payment-header {
                padding: 20px;
            }
            
            .payment-body {
                padding: 20px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="payment-container">
        <div class="payment-header">
            <h1><i class="fas fa-university"></i> ISGI Congo</h1>
            <p>Paiement en ligne des frais académiques</p>
        </div>
        
        <div class="payment-body">
            <!-- Étape 1: Affichage des informations -->
            <div class="payment-info" id="paymentInfo">
                <div class="info-item">
                    <span class="info-label">Nom de l'étudiant:</span>
                    <span class="info-value" id="studentName">Chargement...</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Matricule:</span>
                    <span class="info-value" id="studentMatricule">Chargement...</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Type de frais:</span>
                    <span class="info-value" id="feeType">Chargement...</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Montant à payer:</span>
                    <span class="info-value" id="amountDue">Chargement...</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Frais de transaction:</span>
                    <span class="info-value" id="transactionFee">Chargement...</span>
                </div>
                <div class="info-item total">
                    <span class="info-label">Total à payer:</span>
                    <span class="info-value" style="color: #667eea; font-size: 18px;" id="totalAmount">Chargement...</span>
                </div>
            </div>
            
            <!-- Étape 2: Formulaire de paiement -->
            <div class="payment-form" id="paymentForm">
                <div class="operator-select">
                    <div class="operator-option active" data-operator="mtn">
                        <div class="operator-icon">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <div>MTN Mobile Money</div>
                    </div>
                    <div class="operator-option" data-operator="airtel">
                        <div class="operator-icon">
                            <i class="fas fa-sim-card"></i>
                        </div>
                        <div>Airtel Money</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="phoneNumber"><i class="fas fa-phone"></i> Numéro de téléphone MTN</label>
                    <input type="tel" 
                           id="phoneNumber" 
                           class="form-control" 
                           placeholder="Ex: +242 06 123 45 67"
                           maxlength="13">
                    <div class="error-message" id="phoneError">Veuillez entrer un numéro MTN valide</div>
                </div>
                
                <button class="btn-pay" id="payButton" onclick="initiatePayment()">
                    <i class="fas fa-lock"></i> Payer maintenant
                </button>
                
                <p style="text-align: center; margin-top: 20px; color: #6c757d; font-size: 14px;">
                    <i class="fas fa-shield-alt"></i> Paiement sécurisé via MTN Mobile Money
                </p>
            </div>
            
            <!-- Étape 3: Statut du paiement -->
            <div class="payment-status" id="paymentStatus">
                <div class="status-icon">
                    <div class="loader"></div>
                </div>
                <h3 id="statusTitle">Initialisation du paiement...</h3>
                <p id="statusMessage">Veuillez patienter pendant que nous traitons votre paiement.</p>
                
                <div class="qr-code-container" id="qrCodeContainer" style="display: none;">
                    <div class="qr-code" id="qrCode"></div>
                    <p>Scannez ce QR Code avec votre application Mobile Money</p>
                    <div class="countdown" id="countdown">Temps restant: 05:00</div>
                </div>
                
                <div style="margin-top: 20px;">
                    <button class="btn-pay" onclick="checkPaymentStatus()" id="checkStatusBtn" style="display: none;">
                        <i class="fas fa-sync"></i> Vérifier le statut
                    </button>
                    <button class="btn-pay" onclick="location.reload()" id="newPaymentBtn" style="display: none; background: #6c757d;">
                        <i class="fas fa-redo"></i> Nouveau paiement
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let currentTransactionId = null;
        let countdownInterval = null;
        let timeLeft = 300; // 5 minutes en secondes
        
        // Récupérer les paramètres d'URL
        const urlParams = new URLSearchParams(window.location.search);
        const token = urlParams.get('token');
        const studentId = urlParams.get('student_id');
        const feeTypeId = urlParams.get('fee_type_id');
        const reference = urlParams.get('reference');
        
        // Initialiser la page
        document.addEventListener('DOMContentLoaded', function() {
            if (!token) {
                showError('Token de paiement invalide ou expiré');
                return;
            }
            
            // Charger les informations du paiement
            loadPaymentInfo();
            
            // Configurer la sélection d'opérateur
            setupOperatorSelection();
            
            // Formater le champ téléphone
            setupPhoneInput();
        });
        
        // Charger les informations du paiement
        async function loadPaymentInfo() {
            try {
                const response = await fetch(`/api/payment/info?token=${token}`, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('studentName').textContent = data.student_name;
                    document.getElementById('studentMatricule').textContent = data.matricule;
                    document.getElementById('feeType').textContent = data.fee_type;
                    document.getElementById('amountDue').textContent = formatCurrency(data.amount);
                    document.getElementById('transactionFee').textContent = formatCurrency(data.transaction_fee);
                    document.getElementById('totalAmount').textContent = formatCurrency(data.total_amount);
                } else {
                    showError(data.error || 'Erreur lors du chargement des informations');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showError('Erreur de connexion au serveur');
            }
        }
        
        // Configurer la sélection d'opérateur
        function setupOperatorSelection() {
            const operators = document.querySelectorAll('.operator-option');
            operators.forEach(operator => {
                operator.addEventListener('click', function() {
                    operators.forEach(op => op.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Mettre à jour le placeholder du champ téléphone
                    const phoneInput = document.getElementById('phoneNumber');
                    const operatorType = this.dataset.operator;
                    
                    if (operatorType === 'mtn') {
                        phoneInput.placeholder = 'Ex: +242 06 123 45 67';
                    } else {
                        phoneInput.placeholder = 'Ex: +242 02 123 45 67';
                    }
                });
            });
        }
        
        // Configurer le champ téléphone
        function setupPhoneInput() {
            const phoneInput = document.getElementById('phoneNumber');
            
            phoneInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                
                if (value.startsWith('242')) {
                    value = '+' + value;
                } else if (value.length > 0) {
                    value = '+242' + value;
                }
                
                // Formater l'affichage
                if (value.length > 4) {
                    value = value.substring(0, 4) + ' ' + value.substring(4);
                }
                if (value.length > 7) {
                    value = value.substring(0, 7) + ' ' + value.substring(7, 10);
                }
                if (value.length > 11) {
                    value = value.substring(0, 11) + ' ' + value.substring(11, 13);
                }
                if (value.length > 14) {
                    value = value.substring(0, 14) + ' ' + value.substring(14, 16);
                }
                
                e.target.value = value.substring(0, 16);
                validatePhoneNumber();
            });
        }
        
        // Valider le numéro de téléphone
        function validatePhoneNumber() {
            const phoneInput = document.getElementById('phoneNumber');
            const phoneError = document.getElementById('phoneError');
            const operator = document.querySelector('.operator-option.active').dataset.operator;
            const phone = phoneInput.value.replace(/\s/g, '');
            
            // Validation basique
            if (phone.length < 12) {
                phoneInput.classList.add('error');
                phoneError.style.display = 'block';
                return false;
            }
            
            // Validation par opérateur
            if (operator === 'mtn' && !phone.startsWith('+2420')) {
                phoneInput.classList.add('error');
                phoneError.textContent = 'Veuillez entrer un numéro MTN valide (commence par +24204/05/06)';
                phoneError.style.display = 'block';
                return false;
            }
            
            if (operator === 'airtel' && !phone.startsWith('+24202')) {
                phoneInput.classList.add('error');
                phoneError.textContent = 'Veuillez entrer un numéro Airtel valide (commence par +24202)';
                phoneError.style.display = 'block';
                return false;
            }
            
            phoneInput.classList.remove('error');
            phoneError.style.display = 'none';
            return true;
        }
        
        // Initier le paiement
        async function initiatePayment() {
            if (!validatePhoneNumber()) {
                return;
            }
            
            const operator = document.querySelector('.operator-option.active').dataset.operator;
            const phone = document.getElementById('phoneNumber').value.replace(/\s/g, '');
            
            // Afficher l'écran de chargement
            document.getElementById('paymentForm').style.display = 'none';
            document.getElementById('paymentInfo').style.display = 'none';
            document.getElementById('paymentStatus').style.display = 'block';
            document.getElementById('statusTitle').textContent = 'Initialisation du paiement...';
            document.getElementById('statusMessage').textContent = 'Veuillez patienter pendant que nous traitons votre paiement.';
            
            try {
                const response = await fetch('/api/mobile-money/initiate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': `Bearer ${token}`
                    },
                    body: JSON.stringify({
                        student_id: studentId,
                        phone_number: phone,
                        operator: operator.toUpperCase(),
                        type_frais_id: feeTypeId,
                        reference: reference,
                        token: token
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    currentTransactionId = data.transaction_id;
                    
                    // Mettre à jour l'affichage
                    document.getElementById('statusTitle').textContent = 'Paiement initié';
                    document.getElementById('statusMessage').textContent = 
                        'Veuillez confirmer le paiement sur votre téléphone. Vous recevrez une demande de confirmation.';
                    
                    // Afficher le QR Code si disponible
                    if (data.qr_code_url) {
                        document.getElementById('qrCodeContainer').style.display = 'block';
                        document.getElementById('qrCode').innerHTML = 
                            `<img src="${data.qr_code_url}" alt="QR Code" style="width: 200px; height: 200px;">`;
                        
                        // Démarrer le compte à rebours
                        startCountdown();
                    }
                    
                    // Afficher le bouton de vérification
                    document.getElementById('checkStatusBtn').style.display = 'block';
                    
                    // Vérifier automatiquement le statut après 30 secondes
                    setTimeout(checkPaymentStatus, 30000);
                    
                } else {
                    showError(data.error || 'Erreur lors de l\'initiation du paiement');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showError('Erreur de connexion au serveur');
            }
        }
        
        // Vérifier le statut du paiement
        async function checkPaymentStatus() {
            if (!currentTransactionId) return;
            
            document.getElementById('statusTitle').textContent = 'Vérification du statut...';
            document.getElementById('statusMessage').textContent = 'Vérification en cours, veuillez patienter.';
            document.getElementById('checkStatusBtn').disabled = true;
            
            try {
                const response = await fetch(`/api/mobile-money/status/${currentTransactionId}`, {
                    headers: {
                        'Authorization': `Bearer ${token}`
                    }
                });
                
                const data = await response.json();
                
                if (data.status === 'SUCCESSFUL') {
                    // Paiement réussi
                    document.getElementById('statusTitle').textContent = 'Paiement réussi !';
                    document.getElementById('statusMessage').textContent = 
                        'Votre paiement a été confirmé avec succès. Vous recevrez une confirmation par email.';
                    document.getElementById('statusIcon').innerHTML = '<i class="fas fa-check-circle"></i>';
                    document.getElementById('statusIcon').parentElement.className = 'payment-status status-success';
                    
                    // Arrêter le compte à rebours
                    stopCountdown();
                    
                    // Cacher le bouton de vérification
                    document.getElementById('checkStatusBtn').style.display = 'none';
                    
                    // Afficher le bouton pour nouveau paiement
                    document.getElementById('newPaymentBtn').style.display = 'block';
                    
                    // Rediriger vers la confirmation après 5 secondes
                    setTimeout(() => {
                        window.location.href = `/payment/confirmation/${currentTransactionId}`;
                    }, 5000);
                    
                } else if (data.status === 'PENDING') {
                    // Paiement toujours en attente
                    document.getElementById('statusTitle').textContent = 'En attente de confirmation';
                    document.getElementById('statusMessage').textContent = 
                        'Veuillez confirmer le paiement sur votre téléphone. Le système vérifiera automatiquement le statut.';
                    
                    document.getElementById('checkStatusBtn').disabled = false;
                    
                    // Vérifier à nouveau après 30 secondes
                    setTimeout(checkPaymentStatus, 30000);
                    
                } else if (data.status === 'FAILED' || data.status === 'CANCELLED') {
                    // Paiement échoué
                    showError('Le paiement a échoué ou a été annulé. Veuillez réessayer.');
                    
                } else {
                    // Statut inconnu
                    document.getElementById('checkStatusBtn').disabled = false;
                }
            } catch (error) {
                console.error('Erreur:', error);
                document.getElementById('checkStatusBtn').disabled = false;
            }
        }
        
        // Démarrer le compte à rebours
        function startCountdown() {
            timeLeft = 300; // 5 minutes
            clearInterval(countdownInterval);
            
            countdownInterval = setInterval(() => {
                timeLeft--;
                
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                
                document.getElementById('countdown').textContent = 
                    `Temps restant: ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                if (timeLeft <= 0) {
                    stopCountdown();
                    showError('Le temps pour effectuer le paiement a expiré. Veuillez réessayer.');
                }
            }, 1000);
        }
        
        // Arrêter le compte à rebours
        function stopCountdown() {
            clearInterval(countdownInterval);
        }
        
        // Afficher une erreur
        function showError(message) {
            document.getElementById('paymentForm').style.display = 'none';
            document.getElementById('paymentInfo').style.display = 'none';
            document.getElementById('paymentStatus').style.display = 'block';
            
            document.getElementById('statusTitle').textContent = 'Erreur';
            document.getElementById('statusMessage').textContent = message;
            document.getElementById('statusIcon').innerHTML = '<i class="fas fa-exclamation-circle"></i>';
            document.getElementById('statusIcon').parentElement.className = 'payment-status status-error';
            
            document.getElementById('newPaymentBtn').style.display = 'block';
            document.getElementById('checkStatusBtn').style.display = 'none';
        }
        
        // Formater la monnaie
        function formatCurrency(amount) {
            return new Intl.NumberFormat('fr-CG', {
                style: 'currency',
                currency: 'XAF',
                minimumFractionDigits: 0
            }).format(amount);
        }
        
        // Gérer les messages du parent (si intégré dans un iframe)
        window.addEventListener('message', function(event) {
            if (event.data.type === 'payment_token') {
                // Traiter le token reçu
            }
        });
    </script>
</body>
</html>