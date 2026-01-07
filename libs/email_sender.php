<?php
// libs/email_sender.php

// ============================================
// CHANGEMENT CRITIQUE : Inclure l'autoload de Composer
// ============================================
require_once dirname(__DIR__) . '/vendor/autoload.php';

class EmailSender {
    private static $config = [
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => 'moundouroger@gmail.com',
        'smtp_password' => 'gsfesfcvqwqbkxic',
        'smtp_secure' => 'tls',
        'from_email' => 'noreply@isgi.cg',
        'from_name' => 'ISGI Support',
        'reply_to' => 'support@isgi.cg'
    ];
    
    public static function sendAccountApproval($data) {
        $to = $data['to'];
        $name = $data['name'];
        $role = self::getRoleName($data['role']);
        $adminName = $data['admin_name'];
        
        $subject = "ISGI - Votre compte a été approuvé";
        
        $message = self::getApprovalTemplate($name, $role, $adminName);
        
        if ($message === null) {
            throw new Exception("Erreur : le template d'approbation est vide");
        }
        
        return self::sendEmail($to, $subject, $message);
    }
    
    public static function sendAccountRejection($data) {
        $to = $data['to'];
        $name = $data['name'];
        $reason = $data['reason'];
        $adminName = $data['admin_name'];
        
        $subject = "ISGI - Information concernant votre compte";
        
        $message = self::getRejectionTemplate($name, $reason, $adminName);
        
        if ($message === null) {
            throw new Exception("Erreur : le template de refus est vide");
        }
        
        return self::sendEmail($to, $subject, $message);
    }
    
    public static function sendInformationRequest($data) {
        $to = $data['to'];
        $name = $data['name'];
        $requestedInfo = $data['requested_info'];
        $deadline = $data['deadline'] ?? '48 heures';
        
        $subject = "ISGI - Informations supplémentaires requises";
        
        $message = self::getInfoRequestTemplate($name, $requestedInfo, $deadline);
        
        if ($message === null) {
            throw new Exception("Erreur : le template de demande d'info est vide");
        }
        
        return self::sendEmail($to, $subject, $message);
    }
    
    private static function getApprovalTemplate($name, $role, $adminName) {
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : '/';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #0066cc, #0052a3); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #ffffff; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 10px 10px; }
                .btn-primary { display: inline-block; background: #0066cc; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                .info-box { background: #e8f4fc; border-left: 4px solid #0066cc; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .details { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Institut Supérieur de Gestion et d'Ingénierie</h1>
                    <h2 style='margin: 10px 0 0 0; font-weight: normal;'>Validation de compte</h2>
                </div>
                
                <div class='content'>
                    <h3>Bonjour $name,</h3>
                    
                    <p>Nous sommes heureux de vous informer que votre demande de création de compte sur la plateforme ISGI a été <strong>approuvée</strong> avec succès.</p>
                    
                    <div class='info-box'>
                        <h4 style='margin-top: 0; color: #0066cc;'>Détails de votre compte :</h4>
                        <p><strong>Rôle attribué :</strong> $role</p>
                        <p><strong>Validé par :</strong> $adminName</p>
                        <p><strong>Date d'approbation :</strong> " . date('d/m/Y à H:i') . "</p>
                    </div>
                    
                    <p>Vous pouvez maintenant accéder à votre espace personnel en utilisant les identifiants que vous avez créés lors de votre inscription.</p>
                    
                    <div style='text-align: center;'>
                        <a href='" . $rootPath . "/auth/login.php' class='btn-primary'>Accéder à mon compte</a>
                    </div>
                    
                    <div class='details'>
                        <h4>Prochaines étapes :</h4>
                        <ol>
                            <li>Connectez-vous avec votre email et mot de passe</li>
                            <li>Complétez votre profil si nécessaire</li>
                            <li>Consultez les informations de votre site d'affectation</li>
                            <li>Prenez connaissance du règlement intérieur</li>
                        </ol>
                    </div>
                    
                    <p><strong>Recommandation de sécurité :</strong> Nous vous conseillons de changer votre mot de passe après votre première connexion.</p>
                    
                    <p>Bienvenue dans la communauté ISGI !</p>
                    
                    <p>Cordialement,<br>
                    <strong>L'équipe ISGI</strong></p>
                </div>
                
                <div class='footer'>
                    <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre.</p>
                    <p>Pour toute question, contactez-nous à : support@isgi.cg</p>
                    <p>© " . date('Y') . " Institut Supérieur de Gestion et d'Ingénierie - Tous droits réservés</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    private static function getRejectionTemplate($name, $reason, $adminName) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #ffffff; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 10px 10px; }
                .info-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; border-radius: 4px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Institut Supérieur de Gestion et d'Ingénierie</h1>
                    <h2 style='margin: 10px 0 0 0; font-weight: normal;'>Information concernant votre compte</h2>
                </div>
                
                <div class='content'>
                    <h3>Bonjour $name,</h3>
                    
                    <p>Suite à l'examen de votre demande d'inscription sur la plateforme ISGI, nous regrettons de vous informer que votre compte n'a pas pu être validé.</p>
                    
                    <div class='info-box'>
                        <h4 style='margin-top: 0; color: #dc3545;'>Motif du refus :</h4>
                        <p><strong>$reason</strong></p>
                        <p><strong>Décision prise par :</strong> $adminName</p>
                        <p><strong>Date :</strong> " . date('d/m/Y à H:i') . "</p>
                    </div>
                    
                    <p>Si vous pensez qu'il s'agit d'une erreur ou si vous souhaitez plus d'informations, vous pouvez nous contacter à l'adresse : support@isgi.cg</p>
                    
                    <p>Nous vous remercions de votre compréhension.</p>
                    
                    <p>Cordialement,<br>
                    <strong>L'équipe ISGI</strong></p>
                </div>
                
                <div class='footer'>
                    <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre.</p>
                    <p>© " . date('Y') . " Institut Supérieur de Gestion et d'Ingénierie - Tous droits réservés</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    private static function getInfoRequestTemplate($name, $requestedInfo, $deadline) {
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : '/';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #ffc107, #e0a800); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #ffffff; padding: 30px; border: 1px solid #ddd; border-top: none; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 10px 10px; }
                .info-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
                .btn-primary { display: inline-block; background: #ffc107; color: #212529; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Institut Supérieur de Gestion et d'Ingénierie</h1>
                    <h2 style='margin: 10px 0 0 0; font-weight: normal;'>Informations supplémentaires requises</h2>
                </div>
                
                <div class='content'>
                    <h3>Bonjour $name,</h3>
                    
                    <p>Suite à l'examen de votre demande d'inscription sur la plateforme ISGI, nous avons besoin d'informations supplémentaires pour pouvoir traiter votre compte.</p>
                    
                    <div class='info-box'>
                        <h4 style='margin-top: 0; color: #856404;'>Informations demandées :</h4>
                        <p>$requestedInfo</p>
                        <p><strong>Délai de réponse :</strong> $deadline</p>
                    </div>
                    
                    <p>Veuillez nous fournir ces informations dans les plus brefs délais pour que nous puissions finaliser le traitement de votre demande.</p>
                    
                    <div style='text-align: center;'>
                        <a href='" . $rootPath . "/auth/login.php' class='btn-primary'>Accéder à mon compte</a>
                    </div>
                    
                    <p>Si vous rencontrez des difficultés, contactez-nous à : support@isgi.cg</p>
                    
                    <p>Cordialement,<br>
                    <strong>L'équipe ISGI</strong></p>
                </div>
                
                <div class='footer'>
                    <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre.</p>
                    <p>© " . date('Y') . " Institut Supérieur de Gestion et d'Ingénierie - Tous droits réservés</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    private static function getRoleName($roleId) {
        $roles = [
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
        
        return $roles[$roleId] ?? 'Utilisateur';
    }
    
    private static function sendEmail($to, $subject, $body) {
        // Vérifier que $body n'est pas null
        if ($body === null) {
            error_log("Erreur EmailSender : corps de l'email vide pour $to");
            return false;
        }
        
        try {
            // PHPMailer est déjà inclus par l'autoload au début du fichier
            // Utiliser directement PHPMailer sans fallback sur mail()
            return self::sendWithPHPMailer($to, $subject, $body);
            
        } catch (Exception $e) {
            error_log("Erreur EmailSender : " . $e->getMessage());
            return false;
        }
    }
    
    private static function sendWithPHPMailer($to, $subject, $body) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // ============================================
            // CORRECTION CRITIQUE : Configuration SMTP correcte
            // ============================================
            $mail->isSMTP();
            $mail->Host = self::$config['smtp_host'];            // 'smtp.gmail.com'
            $mail->SMTPAuth = true;
            $mail->Username = self::$config['smtp_username'];    // 'moundouroger@gmail.com'
            $mail->Password = self::$config['smtp_password'];    // 'gsfesfcvqwqbkxic'
            $mail->SMTPSecure = self::$config['smtp_secure'];    // 'tls'
            $mail->Port = self::$config['smtp_port'];            // 587
            
            // Options supplémentaires recommandées
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Charset
            $mail->CharSet = 'UTF-8';
            
            // ============================================
            // Destinataires - configuration correcte
            // ============================================
            $mail->setFrom(
                self::$config['from_email'],      // 'noreply@isgi.cg'
                self::$config['from_name']        // 'ISGI Support'
            );
            $mail->addAddress($to);
            $mail->addReplyTo(
                self::$config['reply_to'],        // 'support@isgi.cg'
                'ISGI Support'
            );
            
            // ============================================
            // Contenu de l'email
            // ============================================
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            // Version texte alternative
            if ($body !== null) {
                $mail->AltBody = strip_tags($body);
            } else {
                $mail->AltBody = "Votre compte a été traité. Consultez la version HTML de cet email.";
            }
            
            // ============================================
            // Debug et journalisation
            // ============================================
            $mail->SMTPDebug = 0; // 0 = pas de debug, 1 = erreurs, 2 = messages clients/serveurs
            
            error_log("EmailSender: Tentative d'envoi à $to");
            
            // Envoyer l'email
            $result = $mail->send();
            
            if ($result) {
                error_log("EmailSender: Email envoyé avec succès à $to");
            } else {
                error_log("EmailSender: Échec d'envoi à $to - " . $mail->ErrorInfo);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("EmailSender Exception: " . $e->getMessage());
            throw new Exception("Erreur PHPMailer: " . $e->getMessage());
        }
    }
    
    // NOTE: La fonction sendWithMailFunction() a été supprimée
    // car nous utilisons uniquement PHPMailer maintenant
}
?>