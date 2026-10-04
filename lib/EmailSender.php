<?php
// lib/EmailSender.php - Version complète avec toutes les fonctionnalités
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class EmailSender {
    private static $instance = null;
    private $config;
    
    private function __construct() {
        // Configuration SMTP
        $this->config = [
            'host' => 'smtp.gmail.com',
            'username' => 'moundouroger@gmail.com',
            'password' => 'gsfesfcvqwqbkxic',
            'port' => 587,
            'from_email' => 'noreply@isgi.cg',
            'from_name' => 'ISGI - Plateforme Académique',
            'reply_to' => 'support@isgi.cg',
            'reply_name' => 'Support ISGI'
        ];
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Configure et retourne une instance PHPMailer
     */
    private function getMailer() {
        $mail = new PHPMailer(true);
        
        // Configuration SMTP
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $this->config['port'];
        
        // Configuration générale
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($this->config['from_email'], $this->config['from_name']);
        $mail->addReplyTo($this->config['reply_to'], $this->config['reply_name']);
        
        return $mail;
    }
    
    /**
     * Envoie un code de vérification 2FA
     */
    public function sendVerificationCode($toEmail, $toName, $verificationCode) {
        try {
            $mail = $this->getMailer();
            
            // Destinataire
            $mail->addAddress($toEmail, $toName);
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Votre code de vérification ISGI';
            $mail->Body = $this->getVerificationEmailTemplate($toName, $verificationCode);
            $mail->AltBody = "Bonjour $toName,\n\nVotre code de vérification ISGI est : $verificationCode\n\nCe code est valable 10 minutes.";
            
            // Envoi
            if ($mail->send()) {
                error_log("✅ Email de vérification envoyé à: $toEmail");
                return true;
            }
            
            error_log("❌ Échec envoi email à: $toEmail");
            return false;
            
        } catch (Exception $e) {
            error_log("❌ Erreur PHPMailer (verif): " . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Envoie un lien de réinitialisation de mot de passe
     */
    public function sendPasswordResetLink($toEmail, $toName, $resetLink) {
        try {
            $mail = $this->getMailer();
            
            // Destinataire
            $mail->addAddress($toEmail, $toName);
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Réinitialisation de votre mot de passe ISGI';
            $mail->Body = $this->getResetPasswordTemplate($toName, $resetLink);
            $mail->AltBody = "Bonjour $toName,\n\nPour réinitialiser votre mot de passe, cliquez sur ce lien :\n$resetLink\n\nCe lien est valable 1 heure.\n\nSi vous n'avez pas demandé cette réinitialisation, ignorez cet email.";
            
            // Envoi
            if ($mail->send()) {
                error_log("✅ Email de réinitialisation envoyé à: $toEmail");
                return true;
            }
            
            error_log("❌ Échec envoi email réinitialisation à: $toEmail");
            return false;
            
        } catch (Exception $e) {
            error_log("❌ Erreur PHPMailer (reset): " . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Envoie une confirmation de modification de mot de passe
     */
    public function sendPasswordChangedConfirmation($toEmail, $toName) {
        try {
            $mail = $this->getMailer();
            
            // Destinataire
            $mail->addAddress($toEmail, $toName);
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Confirmation de modification de mot de passe ISGI';
            $mail->Body = $this->getPasswordChangedTemplate($toName);
            $mail->AltBody = "Bonjour $toName,\n\nVotre mot de passe ISGI a été modifié avec succès.\n\nSi vous n'êtes pas à l'origine de cette modification, veuillez contacter immédiatement le support à support@isgi.cg\n\nCordialement,\nL'équipe ISGI";
            
            // Envoi
            if ($mail->send()) {
                error_log("✅ Confirmation modification mot de passe envoyée à: $toEmail");
                return true;
            }
            
            error_log("❌ Échec envoi confirmation à: $toEmail");
            return false;
            
        } catch (Exception $e) {
            error_log("❌ Erreur PHPMailer (confirmation): " . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Envoie une notification de nouvelle connexion
     */
    public function sendLoginNotification($toEmail, $toName, $loginInfo) {
        try {
            $mail = $this->getMailer();
            
            // Destinataire
            $mail->addAddress($toEmail, $toName);
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Nouvelle connexion détectée - ISGI';
            $mail->Body = $this->getLoginNotificationTemplate($toName, $loginInfo);
            $mail->AltBody = "Bonjour $toName,\n\nUne nouvelle connexion a été détectée sur votre compte ISGI.\n\nDate: {$loginInfo['date']}\nHeure: {$loginInfo['time']}\nIP: {$loginInfo['ip']}\nNavigateur: {$loginInfo['browser']}\n\nSi ce n'était pas vous, veuillez contacter le support immédiatement.";
            
            // Envoi
            return $mail->send();
            
        } catch (Exception $e) {
            error_log("Erreur PHPMailer (notification): " . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Template pour le code de vérification
     */
    private function getVerificationEmailTemplate($name, $code) {
        $currentYear = date('Y');
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Code de vérification ISGI</title>
            <style>
                body { 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                    background-color: #f5f8ff;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #0066cc, #0052a3); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center; 
                }
                .header img {
                    max-width: 120px;
                    margin-bottom: 15px;
                    border-radius: 8px;
                }
                .content { 
                    padding: 40px 30px; 
                    background: white;
                }
                .code-container { 
                    text-align: center; 
                    margin: 30px 0; 
                    padding: 20px;
                    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
                    border-radius: 10px;
                    border: 2px dashed #0066cc;
                }
                .code { 
                    font-size: 42px; 
                    font-weight: bold; 
                    color: #0066cc; 
                    letter-spacing: 8px; 
                    font-family: 'Courier New', monospace;
                    margin: 15px 0;
                }
                .timer { 
                    color: #666; 
                    font-size: 14px;
                    margin-top: 10px;
                }
                .warning { 
                    background: #fff3cd; 
                    border-left: 4px solid #ffc107; 
                    padding: 15px; 
                    margin: 20px 0; 
                    border-radius: 4px;
                }
                .footer { 
                    text-align: center; 
                    margin-top: 30px; 
                    padding-top: 20px; 
                    border-top: 1px solid #e9ecef; 
                    color: #666; 
                    font-size: 12px; 
                }
                .btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #0066cc;
                    color: white;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 10px 0;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2 style="margin: 0; font-weight: 600;">ISGI - Code de vérification</h2>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;">Plateforme Académique Sécurisée</p>
                </div>
                
                <div class="content">
                    <h3 style="color: #0052a3; margin-bottom: 10px;">Bonjour $name,</h3>
                    
                    <p>Vous avez initié une connexion sécurisée à la plateforme ISGI.</p>
                    <p>Pour compléter l'authentification, utilisez le code suivant :</p>
                    
                    <div class="code-container">
                        <div style="font-size: 16px; color: #666; margin-bottom: 10px;">Votre code de vérification :</div>
                        <div class="code">$code</div>
                        <div class="timer">⚠️ Ce code est valable pendant <strong>10 minutes</strong></div>
                    </div>
                    
                    <div class="warning">
                        <strong>🔒 Sécurité :</strong> Ne partagez jamais ce code avec qui que ce soit. 
                        L'équipe ISGI ne vous demandera jamais votre code de vérification.
                    </div>
                    
                    <p>Si vous n'êtes pas à l'origine de cette demande de connexion, veuillez ignorer cet email et contacter le support.</p>
                    
                    <p style="margin-top: 30px;">
                        Cordialement,<br>
                        <strong>L'équipe ISGI</strong><br>
                        <small>Institut Supérieur de Gestion et d'Ingénierie</small>
                    </p>
                </div>
                
                <div class="footer">
                    <p>© $currentYear ISGI - Tous droits réservés</p>
                    <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
                </div>
            </div>
        </body>
        </html>
HTML;
    }
    
    /**
     * Template pour la réinitialisation de mot de passe
     */
    private function getResetPasswordTemplate($name, $link) {
        $currentYear = date('Y');
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Réinitialisation de mot de passe ISGI</title>
            <style>
                body { 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                    background-color: #f5f8ff;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #0066cc, #0052a3); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center; 
                }
                .content { 
                    padding: 40px 30px; 
                    background: white;
                }
                .btn-container {
                    text-align: center;
                    margin: 30px 0;
                }
                .btn {
                    display: inline-block;
                    padding: 14px 35px;
                    background: linear-gradient(135deg, #0066cc, #0052a3);
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 16px;
                    box-shadow: 0 4px 8px rgba(0,102,204,0.2);
                    transition: all 0.3s;
                }
                .btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 12px rgba(0,102,204,0.3);
                }
                .link-box {
                    background: #f8f9fa;
                    border-radius: 8px;
                    padding: 15px;
                    margin: 20px 0;
                    border-left: 4px solid #0066cc;
                    word-break: break-all;
                    font-family: 'Courier New', monospace;
                    font-size: 14px;
                }
                .warning { 
                    background: #fff3cd; 
                    border-left: 4px solid #ffc107; 
                    padding: 15px; 
                    margin: 20px 0; 
                    border-radius: 4px;
                }
                .info { 
                    background: #d1ecf1; 
                    border-left: 4px solid #17a2b8; 
                    padding: 15px; 
                    margin: 20px 0; 
                    border-radius: 4px;
                }
                .footer { 
                    text-align: center; 
                    margin-top: 30px; 
                    padding-top: 20px; 
                    border-top: 1px solid #e9ecef; 
                    color: #666; 
                    font-size: 12px; 
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2 style="margin: 0; font-weight: 600;">Réinitialisation de mot de passe</h2>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;">ISGI - Plateforme Académique</p>
                </div>
                
                <div class="content">
                    <h3 style="color: #0052a3; margin-bottom: 10px;">Bonjour $name,</h3>
                    
                    <p>Vous avez demandé la réinitialisation de votre mot de passe ISGI.</p>
                    
                    <div class="btn-container">
                        <a href="$link" class="btn">
                            🔐 Réinitialiser mon mot de passe
                        </a>
                    </div>
                    
                    <p>Ou copiez-collez ce lien dans votre navigateur :</p>
                    <div class="link-box">
                        $link
                    </div>
                    
                    <div class="info">
                        <strong>⏰ Validité :</strong> Ce lien est valable pendant <strong>1 heure</strong>.
                    </div>
                    
                    <div class="warning">
                        <strong>⚠️ Sécurité importante :</strong><br>
                        • Ne partagez jamais ce lien<br>
                        • Si vous n'avez pas fait cette demande, ignorez cet email<br>
                        • Après réinitialisation, utilisez un mot de passe fort et unique
                    </div>
                    
                    <p style="margin-top: 30px;">
                        Besoin d'aide ? Contactez le support : <a href="mailto:support@isgi.cg">support@isgi.cg</a>
                    </p>
                    
                    <p>
                        Cordialement,<br>
                        <strong>L'équipe ISGI</strong><br>
                        <small>Institut Supérieur de Gestion et d'Ingénierie</small>
                    </p>
                </div>
                
                <div class="footer">
                    <p>© $currentYear ISGI - Tous droits réservés</p>
                    <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
                </div>
            </div>
        </body>
        </html>
HTML;
    }
    
    /**
     * Template pour confirmation de modification de mot de passe
     */
    private function getPasswordChangedTemplate($name) {
        $currentYear = date('Y');
        $currentDate = date('d/m/Y à H:i');
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Confirmation modification mot de passe ISGI</title>
            <style>
                body { 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                    background-color: #f5f8ff;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #28a745, #1e7e34); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center; 
                }
                .content { 
                    padding: 40px 30px; 
                    background: white;
                }
                .success-icon {
                    text-align: center;
                    font-size: 60px;
                    color: #28a745;
                    margin: 20px 0;
                }
                .info-card {
                    background: #f8f9fa;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 20px 0;
                    border-left: 4px solid #28a745;
                }
                .warning { 
                    background: #fff3cd; 
                    border-left: 4px solid #ffc107; 
                    padding: 15px; 
                    margin: 20px 0; 
                    border-radius: 4px;
                }
                .footer { 
                    text-align: center; 
                    margin-top: 30px; 
                    padding-top: 20px; 
                    border-top: 1px solid #e9ecef; 
                    color: #666; 
                    font-size: 12px; 
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2 style="margin: 0; font-weight: 600;">✅ Modification réussie</h2>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;">Confirmation de sécurité</p>
                </div>
                
                <div class="content">
                    <div class="success-icon">
                        🔐
                    </div>
                    
                    <h3 style="color: #1e7e34; margin-bottom: 10px; text-align: center;">Bonjour $name,</h3>
                    
                    <p style="text-align: center;">Votre mot de passe ISGI a été modifié avec succès.</p>
                    
                    <div class="info-card">
                        <strong>📅 Date :</strong> $currentDate<br>
                        <strong>📱 Action :</strong> Modification du mot de passe<br>
                        <strong>🔒 Statut :</strong> <span style="color: #28a745;">Modification confirmée</span>
                    </div>
                    
                    <div class="warning">
                        <strong>⚠️ Sécurité importante :</strong><br><br>
                        
                        <strong>Si vous êtes à l'origine de cette modification :</strong><br>
                        • Votre nouveau mot de passe est maintenant actif<br>
                        • Déconnectez-vous de tous les autres appareils si nécessaire<br>
                        • Conservez votre mot de passe en lieu sûr<br><br>
                        
                        <strong>Si vous N'ÊTES PAS à l'origine de cette modification :</strong><br>
                        • Contactez IMMÉDIATEMENT le support technique<br>
                        • Votre compte pourrait être compromis<br>
                        • Email de contact : <a href="mailto:support@isgi.cg" style="color: #dc3545; font-weight: bold;">support@isgi.cg</a>
                    </div>
                    
                    <p style="margin-top: 30px; text-align: center;">
                        Pour toute question concernant la sécurité de votre compte,<br>
                        n'hésitez pas à contacter notre équipe de support.
                    </p>
                    
                    <p style="text-align: center;">
                        Cordialement,<br>
                        <strong>L'équipe de sécurité ISGI</strong><br>
                        <small>Institut Supérieur de Gestion et d'Ingénierie</small>
                    </p>
                </div>
                
                <div class="footer">
                    <p>© $currentYear ISGI - Service de sécurité des comptes</p>
                    <p>Cet email a été envoyé automatiquement suite à une modification de sécurité.</p>
                </div>
            </div>
        </body>
        </html>
HTML;
    }
    
    /**
     * Template pour notification de connexion
     */
    private function getLoginNotificationTemplate($name, $loginInfo) {
        $currentYear = date('Y');
        
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Nouvelle connexion - ISGI</title>
            <style>
                body { 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    margin: 0;
                    padding: 0;
                    background-color: #f5f8ff;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                }
                .header { 
                    background: linear-gradient(135deg, #ff6b35, #e64a19); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center; 
                }
                .content { 
                    padding: 40px 30px; 
                    background: white;
                }
                .login-info {
                    background: #f8f9fa;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 20px 0;
                    border-left: 4px solid #ff6b35;
                }
                .login-info div {
                    margin: 10px 0;
                    padding: 8px 0;
                    border-bottom: 1px solid #e9ecef;
                }
                .login-info div:last-child {
                    border-bottom: none;
                }
                .warning { 
                    background: #fff3cd; 
                    border-left: 4px solid #ffc107; 
                    padding: 15px; 
                    margin: 20px 0; 
                    border-radius: 4px;
                }
                .danger { 
                    background: #f8d7da; 
                    border-left: 4px solid #dc3545; 
                    padding: 15px; 
                    margin: 20px 0; 
                    border-radius: 4px;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #dc3545;
                    color: white;
                    text-decoration: none;
                    border-radius: 5px;
                    font-weight: 600;
                }
                .footer { 
                    text-align: center; 
                    margin-top: 30px; 
                    padding-top: 20px; 
                    border-top: 1px solid #e9ecef; 
                    color: #666; 
                    font-size: 12px; 
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2 style="margin: 0; font-weight: 600;">⚠️ Nouvelle connexion détectée</h2>
                    <p style="margin: 10px 0 0 0; opacity: 0.9;">Notification de sécurité</p>
                </div>
                
                <div class="content">
                    <h3 style="color: #e64a19; margin-bottom: 10px;">Bonjour $name,</h3>
                    
                    <p>Une nouvelle connexion a été détectée sur votre compte ISGI :</p>
                    
                    <div class="login-info">
                        <div><strong>📅 Date :</strong> {$loginInfo['date']}</div>
                        <div><strong>⏰ Heure :</strong> {$loginInfo['time']}</div>
                        <div><strong>🌐 Adresse IP :</strong> {$loginInfo['ip']}</div>
                        <div><strong>🖥️ Navigateur :</strong> {$loginInfo['browser']}</div>
                        <div><strong>📍 Localisation estimée :</strong> {$loginInfo['location']}</div>
                    </div>
                    
                    <div class="warning">
                        <strong>Si c'était vous :</strong><br>
                        Vous pouvez ignorer cette notification. Assurez-vous de vous déconnecter des appareils publics.
                    </div>
                    
                    <div class="danger">
                        <strong>Si ce n'était pas vous :</strong><br>
                        Votre compte pourrait être compromis. Prenez immédiatement les mesures suivantes :<br><br>
                        1. <a href="#" style="color: #dc3545; font-weight: bold;">Changer votre mot de passe</a><br>
                        2. Vérifier l'historique des connexions<br>
                        3. Contacter le support technique<br><br>
                        
                        <div style="text-align: center; margin-top: 15px;">
                            <a href="mailto:support@isgi.cg" class="btn">🚨 Contacter le support</a>
                        </div>
                    </div>
                    
                    <p style="margin-top: 30px;">
                        Pour votre sécurité, nous surveillons toutes les activités suspectes sur votre compte.
                    </p>
                    
                    <p>
                        Cordialement,<br>
                        <strong>L'équipe de sécurité ISGI</strong><br>
                        <small>Institut Supérieur de Gestion et d'Ingénierie</small>
                    </p>
                </div>
                
                <div class="footer">
                    <p>© $currentYear ISGI - Service de surveillance de sécurité</p>
                    <p>Cette notification est envoyée automatiquement pour protéger votre compte.</p>
                </div>
            </div>
        </body>
        </html>
HTML;
    }
    
    /**
     * Test la connexion SMTP
     */
    public function testConnection() {
        try {
            $mail = $this->getMailer();
            $mail->smtpConnect();
            $mail->smtpClose();
            return ['success' => true, 'message' => 'Connexion SMTP réussie'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Erreur SMTP: ' . $e->getMessage()];
        }
    }
    
    /**
     * Définir des identifiants SMTP personnalisés
     */
    public function setSMTPCredentials($host, $username, $password, $port = 587) {
        $this->config['host'] = $host;
        $this->config['moundouroger@gmail.com'] = $username;
        $this->config['gsfesfcvqwqbkxic'] = $password;
        $this->config['port'] = $port;
    }
}