<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <script>
        if (window.location.protocol === 'file:') {
            window.location.replace('http://localhost:5180/');
        }
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Création et Gestion des Comptes Utilisateurs</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Supabase JS Client v2 -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>

    <style>
        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
            --primary-light: #3b82f6;
            --accent: #0284c7;
            --bg-page: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-dark: #0f172a;
            --text-muted: #64748b;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-dark);
            min-height: 100vh;
            padding-bottom: 60px;
        }

        /* Top Navbar */
        .navbar-custom {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            box-shadow: 0 4px 20px -4px rgba(0, 0, 0, 0.15);
            padding: 14px 0;
        }

        .navbar-brand {
            font-weight: 800;
            font-size: 1.25rem;
            color: #ffffff !important;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-badge {
            width: 42px;
            height: 42px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #ffffff;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 10px #10b981;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.9); }
        }

        /* Cards */
        .glass-card {
            background: var(--card-bg);
            border-radius: 20px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.05);
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .card-header-custom {
            padding: 24px 28px 18px;
            border-bottom: 1px solid var(--border-color);
            background: #ffffff;
        }

        .card-header-custom h2 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body-custom {
            padding: 28px;
        }

        /* Role Selector Cards */
        .role-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        @media (max-width: 576px) {
            .role-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .role-card {
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            padding: 10px 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #ffffff;
            position: relative;
        }

        .role-card:hover {
            border-color: var(--primary-light);
            transform: translateY(-2px);
        }

        .role-card.active {
            border-color: var(--primary);
            background: #eff6ff;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.12);
        }

        .role-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 6px;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .role-title {
            font-size: 0.76rem;
            font-weight: 700;
            margin-bottom: 2px;
            color: var(--text-dark);
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .role-subtitle {
            font-size: 0.65rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Form Controls */
        .form-label-custom {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
            display: block;
        }

        .input-group-custom {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1rem;
            z-index: 5;
        }

        .form-control-custom {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border-radius: 12px;
            border: 1.5px solid var(--border-color);
            font-size: 0.92rem;
            font-family: inherit;
            color: var(--text-dark);
            background-color: #f8fafc;
            transition: all 0.2s ease;
        }

        .form-control-custom:focus {
            outline: none;
            border-color: var(--primary);
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
        }

        .btn-toggle-pwd {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
        }

        .btn-toggle-pwd:hover {
            color: var(--text-dark);
        }

        .btn-submit-custom {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 14px;
            padding: 14px 24px;
            font-weight: 700;
            font-size: 0.95rem;
            width: 100%;
            box-shadow: 0 4px 15px rgba(30, 64, 175, 0.3);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-submit-custom:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4);
            color: white;
        }

        .btn-submit-custom:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        /* Badges */
        .badge-role {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-admin { background: #fee2e2; color: #b91c1c; }
        .badge-dac { background: #e0f2fe; color: #0284c7; }
        .badge-comptable { background: #dcfce7; color: #15803d; }
        .badge-secretariat { background: #f3e8ff; color: #7e22ce; }
        .badge-secretaire-dac { background: #cffafe; color: #0891b2; }
        .badge-surveillant { background: #dbeafe; color: #1d4ed8; }
        .badge-informaticien { background: #e0e7ff; color: #4338ca; }
        .badge-professeur { background: #fef3c7; color: #b45309; }
        .badge-etudiant { background: #f1f5f9; color: #334155; }
        .badge-tuteur { background: #fef9c3; color: #854d0e; }

        /* User Table */
        .table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table-custom th {
            background: #f8fafc;
            padding: 14px 16px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }

        .table-custom td {
            padding: 16px;
            font-size: 0.9rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        .table-custom tr:hover td {
            background: #f8fafc;
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            color: white;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 18px 22px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1.2;
            color: var(--text-dark);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* Tips Alert */
        .info-callout {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .info-callout i {
            font-size: 1.3rem;
            color: #16a34a;
            margin-top: 2px;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <nav class="navbar-custom">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center w-100">
                <a class="navbar-brand" href="#">
                    <div class="logo-badge">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <div>ISGI SYSTEM</div>
                        <div style="font-size: 0.72rem; font-weight: 500; color: #93c5fd; letter-spacing: 0;">Portail Central de Création & Gestion des Comptes</div>
                    </div>
                </a>
                <div class="d-flex align-items-center gap-3">
                    <div class="status-pill" id="supabase-status">
                        <div class="status-dot"></div>
                        <span>Supabase Auth & DB Connecté</span>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container-fluid px-4 mt-4">
        
        <!-- KPI Stats Bar -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card shadow-sm">
                    <div class="stat-icon bg-primary-subtle text-primary">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="stat-total">0</div>
                        <div class="stat-label">Total Utilisateurs</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card shadow-sm">
                    <div class="stat-icon bg-danger-subtle text-danger">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="stat-admin">0</div>
                        <div class="stat-label">Direction & Admin</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card shadow-sm">
                    <div class="stat-icon bg-success-subtle text-success">
                        <i class="fa-solid fa-briefcase"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="stat-gestion">0</div>
                        <div class="stat-label">Gestionnaires & Pédagogie</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card shadow-sm">
                    <div class="stat-icon bg-info-subtle text-info" style="background: #e0f2fe; color: #0284c7;">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <div class="stat-value" id="stat-etudiants">0</div>
                        <div class="stat-label">Étudiants & Tuteurs</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Astuce Pro Supabase -->
        <div class="info-callout">
            <i class="fa-solid fa-circle-check"></i>
            <div class="text-sm">
                <strong class="text-success d-block mb-0.5">Synchronisation Automatique Directe :</strong>
                Chaque utilisateur créé est simultanément inscrit dans <strong>Supabase Auth</strong> (pour pouvoir se connecter avec son mot de passe) et dans la table <strong><code>utilisateurs</code></strong> (avec son rôle et ses accès), ainsi que dans <strong><code>user_presences</code></strong> pour être immédiatement visible dans l'annuaire et le Messenger.
            </div>
        </div>

        <div class="row g-4">
            
            <!-- LEFT COLUMN: Formulaire de Création -->
            <div class="col-lg-5">
                <div class="glass-card shadow-sm">
                    <div class="card-header-custom">
                        <h2>
                            <i class="fa-solid fa-user-plus text-primary"></i>
                            Créer un Compte Utilisateur
                        </h2>
                    </div>

                    <div class="card-body-custom">
                        
                        <div id="alert-box" class="d-none alert mb-3" role="alert"></div>

                        <form id="create-user-form">
                            
                            <!-- Sélecteur de Rôle (Les 10 Rôles Utilisateurs) -->
                            <label class="form-label-custom">1. Sélectionner le Rôle / Fonction (10 Rôles)</label>
                            <div class="role-grid">
                                <!-- 1. Admin Principal -->
                                <div class="role-card active" data-role="admin_principal" onclick="selectRole('admin_principal')">
                                    <div class="role-icon" style="background: #fee2e2; color: #b91c1c;">
                                        <i class="fa-solid fa-shield-halved"></i>
                                    </div>
                                    <div class="role-title">Admin Principal</div>
                                    <div class="role-subtitle">Direction</div>
                                </div>

                                <!-- 2. DAC -->
                                <div class="role-card" data-role="dac" onclick="selectRole('dac')">
                                    <div class="role-icon" style="background: #e0f2fe; color: #0284c7;">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                    </div>
                                    <div class="role-title">DAC</div>
                                    <div class="role-subtitle">Académique</div>
                                </div>

                                <!-- 3. Comptable -->
                                <div class="role-card" data-role="comptable" onclick="selectRole('comptable')">
                                    <div class="role-icon" style="background: #dcfce7; color: #15803d;">
                                        <i class="fa-solid fa-calculator"></i>
                                    </div>
                                    <div class="role-title">Comptable</div>
                                    <div class="role-subtitle">Finances</div>
                                </div>

                                <!-- 4. Secrétariat -->
                                <div class="role-card" data-role="secretariat" onclick="selectRole('secretariat')">
                                    <div class="role-icon" style="background: #f3e8ff; color: #7e22ce;">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </div>
                                    <div class="role-title">Secrétariat</div>
                                    <div class="role-subtitle">Scolarité</div>
                                </div>

                                <!-- 5. Secrétaire du DAC -->
                                <div class="role-card" data-role="secretaire_dac" onclick="selectRole('secretaire_dac')">
                                    <div class="role-icon" style="background: #cffafe; color: #0891b2;">
                                        <i class="fa-solid fa-file-signature"></i>
                                    </div>
                                    <div class="role-title">Secrétaire DAC</div>
                                    <div class="role-subtitle">Assistance DAC</div>
                                </div>

                                <!-- 6. Surveillant -->
                                <div class="role-card" data-role="surveillant" onclick="selectRole('surveillant')">
                                    <div class="role-icon" style="background: #dbeafe; color: #1d4ed8;">
                                        <i class="fa-solid fa-clipboard-check"></i>
                                    </div>
                                    <div class="role-title">Surveillant</div>
                                    <div class="role-subtitle">Discipline</div>
                                </div>

                                <!-- 7. Informaticiens -->
                                <div class="role-card" data-role="informaticien" onclick="selectRole('informaticien')">
                                    <div class="role-icon" style="background: #e0e7ff; color: #4338ca;">
                                        <i class="fa-solid fa-laptop-code"></i>
                                    </div>
                                    <div class="role-title">Informaticien</div>
                                    <div class="role-subtitle">Support SI</div>
                                </div>

                                <!-- 8. Professeur -->
                                <div class="role-card" data-role="professeur" onclick="selectRole('professeur')">
                                    <div class="role-icon" style="background: #fef3c7; color: #b45309;">
                                        <i class="fa-solid fa-chalkboard-user"></i>
                                    </div>
                                    <div class="role-title">Professeur</div>
                                    <div class="role-subtitle">Enseignant</div>
                                </div>

                                <!-- 9. Étudiants -->
                                <div class="role-card" data-role="etudiant" onclick="selectRole('etudiant')">
                                    <div class="role-icon" style="background: #f1f5f9; color: #334155;">
                                        <i class="fa-solid fa-user-graduate"></i>
                                    </div>
                                    <div class="role-title">Étudiant</div>
                                    <div class="role-subtitle">Apprenant</div>
                                </div>

                                <!-- 10. Tuteur -->
                                <div class="role-card" data-role="tuteur" onclick="selectRole('tuteur')">
                                    <div class="role-icon" style="background: #fef9c3; color: #854d0e;">
                                        <i class="fa-solid fa-hands-holding-child"></i>
                                    </div>
                                    <div class="role-title">Tuteur</div>
                                    <div class="role-subtitle">Parent d'élève</div>
                                </div>
                            </div>
                            <input type="hidden" id="selected-role" value="admin_principal">

                            <!-- Nom Complet -->
                            <div class="mb-3">
                                <label class="form-label-custom">Nom et Prénom(s)</label>
                                <div class="input-group-custom">
                                    <i class="fa-solid fa-user input-icon"></i>
                                    <input type="text" id="nom_complet" required class="form-control-custom" placeholder="ex: Dr. Alexandre KOUAME">
                                </div>
                            </div>

                            <!-- Adresse Email -->
                            <div class="mb-3">
                                <label class="form-label-custom">Adresse Email</label>
                                <div class="input-group-custom">
                                    <i class="fa-solid fa-envelope input-icon"></i>
                                    <input type="email" id="email" required class="form-control-custom" placeholder="ex: secretariat@isgi-edu.org">
                                </div>
                            </div>

                            <!-- Mot de passe -->
                            <div class="row g-2 mb-4">
                                <div class="col-sm-6">
                                    <label class="form-label-custom">Mot de Passe</label>
                                    <div class="input-group-custom">
                                        <i class="fa-solid fa-lock input-icon"></i>
                                        <input type="password" id="password" required minlength="6" class="form-control-custom" placeholder="Min. 6 car." style="padding-right: 36px;">
                                        <button type="button" class="btn-toggle-pwd" onclick="togglePassword('password')">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label-custom">Confirmation</label>
                                    <div class="input-group-custom">
                                        <i class="fa-solid fa-lock input-icon"></i>
                                        <input type="password" id="confirm_password" required minlength="6" class="form-control-custom" placeholder="Confirmer">
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" id="btn-submit" class="btn-submit-custom">
                                <i class="fa-solid fa-bolt"></i>
                                <span>Créer et Synchroniser dans Supabase</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Liste et Annuaire des Utilisateurs -->
            <div class="col-lg-7">
                <div class="glass-card shadow-sm">
                    <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h2>
                            <i class="fa-solid fa-address-book text-primary"></i>
                            Utilisateurs Enregistrés
                        </h2>
                        <div class="d-flex align-items-center gap-2">
                            <button onclick="loadUsers()" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                <i class="fa-solid fa-rotate-right me-1"></i> Actualiser
                            </button>
                        </div>
                    </div>

                    <!-- Search Filter Bar -->
                    <div class="px-4 pt-3 pb-2 border-bottom bg-light d-flex gap-2 flex-wrap">
                        <div class="flex-grow-1 position-relative">
                            <input type="text" id="search-input" onkeyup="filterUsers()" class="form-control form-control-sm rounded-pill ps-4" placeholder="Rechercher par nom, email ou rôle...">
                            <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="left: 14px; top: 9px; font-size: 0.8rem;"></i>
                        </div>
                        <select id="role-filter" onchange="filterUsers()" class="form-select form-select-sm rounded-pill w-auto">
                            <option value="all">Tous les rôles (10 rôles)</option>
                            <option value="admin_principal">1. Admin Principal</option>
                            <option value="dac">2. DAC</option>
                            <option value="comptable">3. Comptable</option>
                            <option value="secretariat">4. Secrétariat</option>
                            <option value="secretaire_dac">5. Secrétaire du DAC</option>
                            <option value="surveillant">6. Surveillant</option>
                            <option value="informaticien">7. Informaticien</option>
                            <option value="professeur">8. Professeur</option>
                            <option value="etudiant">9. Étudiant</option>
                            <option value="tuteur">10. Tuteur</option>
                        </select>
                    </div>

                    <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Rôle</th>
                                    <th>Statut</th>
                                    <th>Création</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="users-table-body">
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                                        <div>Chargement des utilisateurs depuis Supabase...</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Scripts Logic -->
    <script>
        // Configuration Client Supabase
        const SUPABASE_URL = 'https://vbdhmgrysrerlmgumafx.supabase.co';
        const SUPABASE_ANON_KEY = 'sb_publishable_sqJUSK-p5mF2Acy_bhxhAQ_nt_t6Fax';
        const supabaseClient = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY, {
            auth: {
                persistSession: false,
                autoRefreshToken: false,
                detectSessionInUrl: false
            }
        });

        let allUsers = [];

        // Dictionnaire exact des 10 rôles définis par l'utilisateur
        const ROLE_CONFIG = {
            // 1. Admin Principal
            admin_principal: { label: 'Admin Principal', badge: 'badge-admin', color: '#b91c1c' },
            admin: { label: 'Admin Principal', badge: 'badge-admin', color: '#b91c1c' },

            // 2. DAC
            dac: { label: 'DAC', badge: 'badge-dac', color: '#0284c7' },

            // 3. Comptable
            comptable: { label: 'Comptable', badge: 'badge-comptable', color: '#15803d' },

            // 4. Secrétariat
            secretariat: { label: 'Secrétariat', badge: 'badge-secretariat', color: '#7e22ce' },

            // 5. Secrétaire du DAC
            secretaire_dac: { label: 'Secrétaire du DAC', badge: 'badge-secretaire-dac', color: '#0891b2' },
            'secretaire du dac': { label: 'Secrétaire du DAC', badge: 'badge-secretaire-dac', color: '#0891b2' },

            // 6. Surveillant
            surveillant: { label: 'Surveillant', badge: 'badge-surveillant', color: '#1d4ed8' },

            // 7. Informaticiens
            informaticien: { label: 'Informaticien', badge: 'badge-informaticien', color: '#4338ca' },
            informaticiens: { label: 'Informaticien', badge: 'badge-informaticien', color: '#4338ca' },

            // 8. Professeur
            professeur: { label: 'Professeur', badge: 'badge-professeur', color: '#b45309' },
            enseignant: { label: 'Professeur', badge: 'badge-professeur', color: '#b45309' },

            // 9. Étudiants
            etudiant: { label: 'Étudiant', badge: 'badge-etudiant', color: '#334155' },
            etudiants: { label: 'Étudiant', badge: 'badge-etudiant', color: '#334155' },

            // 10. Tuteur
            tuteur: { label: 'Tuteur', badge: 'badge-tuteur', color: '#854d0e' }
        };

        // Sélection visuelle de rôle
        function selectRole(role) {
            document.querySelectorAll('.role-card').forEach(c => c.classList.remove('active'));
            const target = document.querySelector(`.role-card[data-role="${role}"]`);
            if (target) target.classList.add('active');
            document.getElementById('selected-role').value = role;
        }

        // Bascule mot de passe visible/masqué
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.parentElement.querySelector('.btn-toggle-pwd i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-regular fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa-regular fa-eye';
            }
        }

        // Afficher une alerte
        function showAlert(msg, type = 'danger') {
            const box = document.getElementById('alert-box');
            box.className = `alert alert-${type} mb-3 py-2 px-3 text-sm`;
            box.innerHTML = `<i class="fa-solid fa-${type === 'success' ? 'circle-check' : 'circle-exclamation'} me-2"></i>${msg}`;
            box.classList.remove('d-none');
        }

        function hideAlert() {
            document.getElementById('alert-box').classList.add('d-none');
        }

        // Initiales pour avatar
        function getInitials(name) {
            if (!name) return 'U';
            const parts = name.trim().split(' ');
            if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
            return name.substring(0, 2).toUpperCase();
        }

        // Couleur d'avatar
        function getAvatarColor(role) {
            return ROLE_CONFIG[role]?.color || '#1e40af';
        }

        // Charger les utilisateurs depuis Supabase
        async function loadUsers() {
            try {
                const { data, error } = await supabaseClient
                    .from('utilisateurs')
                    .select('*')
                    .order('date_creation', { ascending: false });

                if (error) {
                    console.error('Erreur Supabase:', error);
                    document.getElementById('users-table-body').innerHTML = `
                        <tr>
                            <td colspan="5" class="text-center py-4 text-danger">
                                <i class="fa-solid fa-triangle-exclamation mb-1"></i>
                                <div>Erreur de lecture de la table utilisateurs</div>
                                <div class="text-xs text-muted">${error.message}</div>
                            </td>
                        </tr>
                    `;
                    return;
                }

                allUsers = data || [];
                updateStats();
                renderUsersTable(allUsers);

            } catch (err) {
                console.error(err);
            }
        }

        // Mettre à jour les compteurs
        function updateStats() {
            document.getElementById('stat-total').innerText = allUsers.length;
            
            // Direction & DAC (admin_principal, admin, dac, secretaire_dac)
            const adminRoles = ['admin_principal', 'admin', 'dac', 'secretaire_dac', 'secretaire du dac'];
            document.getElementById('stat-admin').innerText = allUsers.filter(u => adminRoles.includes(u.role)).length;

            // Gestionnaires & Technique (comptable, secretariat, informaticien, informaticiens, surveillant)
            const gestionRoles = ['comptable', 'secretariat', 'informaticien', 'informaticiens', 'surveillant'];
            document.getElementById('stat-gestion').innerText = allUsers.filter(u => gestionRoles.includes(u.role)).length;

            // Pédagogie & Apprenants (professeur, enseignant, etudiant, etudiants, tuteur)
            const etudRoles = ['professeur', 'enseignant', 'etudiant', 'etudiants', 'tuteur'];
            document.getElementById('stat-etudiants').innerText = allUsers.filter(u => etudRoles.includes(u.role)).length;
        }

        // Filtrer les utilisateurs
        function filterUsers() {
            const query = document.getElementById('search-input').value.toLowerCase().trim();
            const role = document.getElementById('role-filter').value;

            const filtered = allUsers.filter(u => {
                const matchQuery = !query || 
                    (u.nom_complet && u.nom_complet.toLowerCase().includes(query)) ||
                    (u.email && u.email.toLowerCase().includes(query)) ||
                    (u.role && u.role.toLowerCase().includes(query));
                
                const matchRole = (role === 'all') || 
                    (u.role === role) ||
                    (role === 'admin_principal' && (u.role === 'admin' || u.role === 'admin_principal')) ||
                    (role === 'secretaire_dac' && (u.role === 'secretaire du dac' || u.role === 'secretaire_dac')) ||
                    (role === 'informaticien' && (u.role === 'informaticiens' || u.role === 'informaticien')) ||
                    (role === 'professeur' && (u.role === 'enseignant' || u.role === 'professeur')) ||
                    (role === 'etudiant' && (u.role === 'etudiants' || u.role === 'etudiant'));

                return matchQuery && matchRole;
            });

            renderUsersTable(filtered);
        }

        // Rendu du tableau
        function renderUsersTable(users) {
            const tbody = document.getElementById('users-table-body');
            
            if (users.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-users-slash fa-2x mb-2 text-secondary opacity-50"></i>
                            <div>Aucun utilisateur trouvé</div>
                            <div class="text-xs">Remplissez le formulaire de gauche pour créer le premier utilisateur.</div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = users.map(u => {
                const conf = ROLE_CONFIG[u.role] || { label: u.role, badge: 'badge-secondary', color: '#64748b' };
                const dateStr = u.date_creation ? new Date(u.date_creation).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '-';
                
                return `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2.5">
                                <div class="user-avatar" style="background: ${conf.color};">
                                    ${getInitials(u.nom_complet)}
                                </div>
                                <div>
                                    <div class="fw-bold text-dark">${u.nom_complet || 'Sans nom'}</div>
                                    <div class="text-muted" style="font-size: 0.78rem;">${u.email}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge-role ${conf.badge}">
                                ${conf.label}
                            </span>
                        </td>
                        <td>
                            <span class="badge ${u.statut === 'bloque' ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'} rounded-pill px-2.5 py-1 text-xs">
                                <i class="fa-solid fa-${u.statut === 'bloque' ? 'lock' : 'circle-check'} me-1"></i>
                                ${u.statut === 'bloque' ? 'Bloqué' : 'Actif'}
                            </span>
                        </td>
                        <td class="text-muted text-xs">
                            ${dateStr}
                        </td>
                        <td class="text-end">
                            <button onclick="copyCredentials('${u.email}')" class="btn btn-sm btn-light rounded-pill px-2.5 py-1 text-xs" title="Copier Email">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                            <button onclick="toggleUserStatus('${u.id}', '${u.statut === 'bloque' ? 'actif' : 'bloque'}')" class="btn btn-sm btn-light rounded-pill px-2.5 py-1 text-xs ms-1" title="${u.statut === 'bloque' ? 'Débloquer' : 'Bloquer'}">
                                <i class="fa-solid fa-${u.statut === 'bloque' ? 'unlock text-success' : 'ban text-warning'}"></i>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Copier l'email
        function copyCredentials(email) {
            navigator.clipboard.writeText(email).then(() => {
                alert(`Adresse email copiée : ${email}`);
            });
        }

        // Basculer statut
        async function toggleUserStatus(id, newStatus) {
            try {
                const { error } = await supabaseClient
                    .from('utilisateurs')
                    .update({ statut: newStatus })
                    .eq('id', id);

                if (!error) {
                    await loadUsers();
                } else {
                    alert('Erreur de mise à jour: ' + error.message);
                }
            } catch (e) {
                console.error(e);
            }
        }

        // Soumission du Formulaire de Création
        document.getElementById('create-user-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert();

            const nomComplet = document.getElementById('nom_complet').value.trim();
            const email = document.getElementById('email').value.trim().toLowerCase();
            const role = document.getElementById('selected-role').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const btnSubmit = document.getElementById('btn-submit');

            if (!nomComplet) {
                showAlert('Veuillez renseigner le nom complet de l’utilisateur.');
                return;
            }

            if (password.length < 6) {
                showAlert('Le mot de passe doit comporter au moins 6 caractères.');
                return;
            }

            if (password !== confirmPassword) {
                showAlert('Les mots de passe ne correspondent pas.');
                return;
            }

            // Désactiver le bouton pendant le traitement
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Inscription & Synchronisation Supabase...`;

            try {
                // 1. Inscription dans Supabase Auth
                const { data: authData, error: authError } = await supabaseClient.auth.signUp({
                    email: email,
                    password: password,
                    options: {
                        data: {
                            nom_complet: nomComplet,
                            role: role
                        }
                    }
                });

                if (authError) {
                    console.error('Erreur Supabase Auth:', authError);
                    const errorMsg = (authError.message || '').toLowerCase();
                    
                    if (errorMsg.includes('rate limit')) {
                        showAlert(`
                            <strong>Limite d'envoi d'emails Supabase atteinte :</strong><br>
                            Pour créer tous vos utilisateurs sans restriction d'email : dans votre console Supabase &rarr; <em>Authentication &rarr; Providers &rarr; Email</em> &rarr; <strong>Désactivez "Confirm email"</strong>. Les comptes seront alors créés instantanément sans attente.
                        `, 'warning');
                    } else if (errorMsg.includes('already registered') || errorMsg.includes('already exists')) {
                        showAlert(`Cette adresse email (${email}) est déjà enregistrée dans Supabase Auth.`, 'danger');
                    } else if (errorMsg.includes('invalid format') || errorMsg.includes('validate email')) {
                        showAlert(`Adresse email invalide : veuillez vérifier le format de l'adresse email (${email}).`, 'danger');
                    } else if (errorMsg.includes('password') || errorMsg.includes('weak')) {
                        showAlert(`Mot de passe insuffisant : ${authError.message}`, 'danger');
                    } else {
                        showAlert(`Erreur Supabase (${authError.status || 400}) : ${authError.message}`, 'danger');
                    }
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = `<i class="fa-solid fa-bolt"></i> Créer et Synchroniser dans Supabase`;
                    return;
                }

                // Vérifier si l'utilisateur existait déjà (identities vide sous Supabase)
                if (authData?.user?.identities && authData.user.identities.length === 0) {
                    showAlert(`Cette adresse email (${email}) est déjà enregistrée dans Supabase. Veuillez en choisir une autre ou utiliser ce compte existant.`, 'warning');
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = `<i class="fa-solid fa-bolt"></i> Créer et Synchroniser dans Supabase`;
                    return;
                }

                const userId = authData?.user?.id;

                if (!userId) {
                    showAlert("L'inscription n'a pas pu renvoyer d'identifiant Supabase valide.", 'danger');
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = `<i class="fa-solid fa-bolt"></i> Créer et Synchroniser dans Supabase`;
                    return;
                }

                // 2. Synchronisation dans la table 'utilisateurs'
                const { error: dbError } = await supabaseClient
                    .from('utilisateurs')
                    .upsert([{
                        id: userId,
                        email: email,
                        nom_complet: nomComplet,
                        role: role,
                        statut: 'actif',
                        date_creation: new Date().toISOString(),
                        derniere_connexion: new Date().toISOString()
                    }]);

                if (dbError) {
                    console.error('Erreur table utilisateurs:', dbError);
                }

                // 3. Synchronisation dans la table 'user_presences' pour le Messenger
                const roleLibelle = ROLE_CONFIG[role]?.label || role;
                await supabaseClient
                    .from('user_presences')
                    .upsert([{
                        user_id: userId,
                        nom_complet: nomComplet,
                        role: roleLibelle,
                        en_ligne: false,
                        derniere_connexion: new Date().toISOString(),
                        statut_perso: 'Compte initialisé'
                    }]).then(() => {}, () => {});

                // 4. Log dans le journal d'activités
                await supabaseClient
                    .from('journal_activites')
                    .insert([{
                        utilisateur_id: userId,
                        utilisateur_nom: nomComplet,
                        type_action: 'CREATION_UTILISATEUR',
                        description: `Création du compte ${nomComplet} (${roleLibelle}) synchronisé avec Auth et Base de données`
                    }]).then(() => {}, () => {});

                // Succès !
                showAlert(`✅ Le compte pour <strong>${nomComplet}</strong> (${roleLibelle}) a été créé avec succès et synchronisé dans Supabase !`, 'success');
                
                // Réinitialiser le formulaire
                document.getElementById('nom_complet').value = '';
                document.getElementById('email').value = '';
                document.getElementById('password').value = '';
                document.getElementById('confirm_password').value = '';

                // Recharger la liste
                await loadUsers();

            } catch (error) {
                console.error('Erreur générale:', error);
                showAlert(`Erreur inattendue : ${error?.message || error}`, 'danger');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = `<i class="fa-solid fa-bolt"></i> Créer et Synchroniser dans Supabase`;
            }
        });

        // Chargement initial
        window.addEventListener('DOMContentLoaded', () => {
            loadUsers();
        });
    </script>
</body>
</html>