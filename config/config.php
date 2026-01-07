<?php
class Config {
    // URL de l'application
    const APP_URL = 'http://localhost/isgi-system';
    
    // Paramètres de session
    const SESSION_TIMEOUT = 24 * 3600; // 24 heures
    const SESSION_NAME = 'isgi_session';
    
    // Configuration des rôles
    const ROLES = [
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
    
    // Routes par rôle
    const ROLE_DASHBOARDS = [
        1 => 'dashboard_admin_principal.php',
        2 => 'dashboard_admin_site.php',
        3 => 'dashboard_gestionnaire.php',
        4 => 'dashboard_gestionnaire.php',
        5 => 'dashboard_dac.php',
        6 => 'dashboard_surveillant.php',
        7 => 'dashboard_professeur.php',
        8 => 'dashboard_etudiant.php',
        9 => 'dashboard_tuteur.php'
    ];
    
    // Permissions par rôle
    const ROLE_PERMISSIONS = [
        1 => ['all'], // Administrateur Principal - Tous droits
        2 => ['view_site', 'manage_site_users', 'manage_site_students'],
        3 => ['manage_finances', 'manage_inscriptions', 'view_reports'],
        4 => ['manage_inscriptions', 'view_payments'],
        5 => ['manage_academic', 'manage_grades', 'manage_schedules'],
        6 => ['manage_attendance', 'manage_classrooms'],
        7 => ['manage_grades', 'view_students'],
        8 => ['view_grades', 'view_attendance', 'view_schedule'],
        9 => ['view_student_grades', 'view_student_attendance', 'view_payments']
    ];
    
    // Obtenir le dashboard par rôle
    public static function getDashboardByRole($roleId) {
        return self::ROLE_DASHBOARDS[$roleId] ?? 'dashboard_etudiant.php';
    }
    
    // Vérifier si l'utilisateur a la permission
    public static function hasPermission($roleId, $permission) {
        if ($roleId == 1) return true; // Admin principal a tous les droits
        
        $permissions = self::ROLE_PERMISSIONS[$roleId] ?? [];
        return in_array('all', $permissions) || in_array($permission, $permissions);
    }
}
?>