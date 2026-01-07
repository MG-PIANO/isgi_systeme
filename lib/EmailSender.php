<?php
// lib/EmailSender.php - Version avec PHPMailer configuré
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class EmailSender {
    private static $instance = null;
    
    private function __construct() {}
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function sendVerificationCode($toEmail, $toName, $verificationCode) {
        try {
            $mail = new PHPMailer(true);
            
            // Configuration SMTP - MODIFIEZ CES PARAMÈTRES
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';           // Serveur SMTP Gmail
            $mail->SMTPAuth   = true;
            $mail->Username   = 'moundouroger@gmail.com';    // Votre email Gmail
            $mail->Password   = 'gsfesfcvqwqbkxic';   // Mot de passe d'application
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Encodage
            $mail->CharSet = 'UTF-8';
            
            // Expéditeur
            $mail->setFrom('noreply@isgi.cg', 'ISGI - Plateforme Académique');
            $mail->addReplyTo('support@isgi.cg', 'Support ISGI');
            
            // Destinataire
            $mail->addAddress($toEmail, $toName);
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Votre code de vérification ISGI';
            
            // Template HTML
            $mail->Body = $this->getVerificationEmailTemplate($toName, $verificationCode);
            
            // Version texte
            $mail->AltBody = "Bonjour $toName,\n\nVotre code de vérification ISGI est : $verificationCode\n\nCe code est valable 10 minutes.";
            
            // Envoi
            if ($mail->send()) {
                error_log("Email envoyé avec succès à: $toEmail");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Erreur PHPMailer: " . $mail->ErrorInfo);
            return false;
        }
    }
    
    public function sendPasswordResetLink($toEmail, $toName, $resetLink) {
        try {
            $mail = new PHPMailer(true);
            
            // Même configuration SMTP
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'votre.email@gmail.com';
            $mail->Password   = 'votre-mot-de-passe-app';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            $mail->CharSet = 'UTF-8';
            $mail->setFrom('noreply@isgi.cg', 'ISGI - Plateforme Académique');
            $mail->addReplyTo('support@isgi.cg', 'Support ISGI');
            $mail->addAddress($toEmail, $toName);
            
            $mail->isHTML(true);
            $mail->Subject = 'Réinitialisation de votre mot de passe ISGI';
            $mail->Body = $this->getResetPasswordTemplate($toName, $resetLink);
            $mail->AltBody = "Bonjour $toName,\n\nPour réinitialiser votre mot de passe: $resetLink";
            
            return $mail->send();
            
        } catch (Exception $e) {
            error_log("Erreur PHPMailer: " . $mail->ErrorInfo);
            return false;
        }
    }
    
    private function getVerificationEmailTemplate($name, $code) {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Code de vérification ISGI</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f8f9fa; }
                .code { font-size: 32px; font-weight: bold; color: #0066cc; text-align: center; margin: 30px 0; padding: 15px; background: white; border-radius: 8px; letter-spacing: 5px; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                  <img src="../image/logo isgi.jpg" width="70px" >
                    <h2>ISGI - Code de vérification</h2>
                </div>
                <div class="content">
                    <h3>Bonjour ' . htmlspecialchars($name) . ',</h3>

                    <p>Votre code de vérification pour la plateforme ISGI est :</p>
                    <div class="code">' . $code . '</div>
                    <p>Ce code est valable pendant 10 minutes.</p>
                    <p>Si vous n\'avez pas demandé cette vérification, ignorez cet email.</p>
                    <p>Cordialement,<br>L\'équipe ISGI</p>
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' Institut Supérieur de Gestion et d\'Ingénierie</p>
                </div>
            </div>
        </body>
        </html>';
    }
    
    private function getResetPasswordTemplate($name, $link) {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Réinitialisation de mot de passe</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background: #f8f9fa; }
                .btn { display: inline-block; background: #0066cc; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Réinitialisation de mot de passe ISGI</h2>
                </div>
                <div class="content">
                    <h3>Bonjour ' . htmlspecialchars($name) . ',</h3>
                    <p>Cliquez sur le bouton ci-dessous pour réinitialiser votre mot de passe :</p>
                    <div style="text-align: center;">
                        <a href="' . htmlspecialchars($link) . '" class="btn">Réinitialiser mon mot de passe</a>
                    </div>
                    <p>Ou copiez ce lien :<br>' . htmlspecialchars($link) . '</p>
                    <p>Ce lien est valable 1 heure.</p>
                    <p>Cordialement,<br>L\'équipe ISGI</p>
                </div>
                <div class="footer">
                    <p>© ' . date('Y') . ' Institut Supérieur de Gestion et d\'Ingénierie</p>
                </div>
            </div>
        </body>
        </html>';
    }
}