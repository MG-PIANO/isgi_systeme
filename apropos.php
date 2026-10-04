<?php
session_start();
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>À propos de nous - ISGI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --info-color: #17a2b8;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        /* Header */
        header {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logo-text {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .logo-text span {
            color: var(--secondary-color);
        }
        
        .nav-links {
            display: flex;
            gap: 25px;
            align-items: center;
        }
        
        .nav-links a {
            text-decoration: none;
            color: var(--dark-color);
            font-weight: 500;
            transition: color 0.3s;
            padding: 8px 0;
            position: relative;
        }
        
        .nav-links a:hover {
            color: var(--secondary-color);
        }
        
        .nav-links a.active {
            color: var(--secondary-color);
            font-weight: 600;
        }
        
        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--secondary-color);
            transition: width 0.3s;
        }
        
        .nav-links a:hover::after,
        .nav-links a.active::after {
            width: 100%;
        }
        
        .auth-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary {
            background-color: var(--light-color);
            color: var(--dark-color);
            border: 1px solid #ddd;
        }
        
        .btn-secondary:hover {
            background-color: #d5dbdb;
            transform: translateY(-2px);
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 120px 0 80px;
            text-align: center;
            margin-top: 0;
        }
        
        .page-header h1 {
            font-size: 3.5rem;
            margin-bottom: 20px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .page-header p {
            font-size: 1.25rem;
            max-width: 700px;
            margin: 0 auto;
            opacity: 0.9;
        }
        
        /* Content Sections */
        .content-section {
            padding: 80px 0;
        }
        
        .section-title {
            font-size: 2.8rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 50px;
            color: var(--primary-color);
            position: relative;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: var(--secondary-color);
            border-radius: 2px;
        }
        
        .mission-vision {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }
        
        .mission-card, .vision-card, .values-card {
            background: white;
            padding: 40px 30px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
        }
        
        .mission-card:hover, .vision-card:hover, .values-card:hover {
            transform: translateY(-10px);
        }
        
        .mission-card h3, .vision-card h3, .values-card h3 {
            color: var(--secondary-color);
            font-size: 1.8rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Organigramme */
        .org-chart {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 50px;
        }
        
        .org-level {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-bottom: 50px;
            flex-wrap: wrap;
        }
        
        .org-position {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            width: 250px;
            transition: all 0.3s ease;
            border-top: 4px solid var(--secondary-color);
        }
        
        .org-position:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .org-position i {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 15px;
        }
        
        .org-position h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .org-position p {
            color: #666;
            font-size: 0.95rem;
        }
        
        /* Values Grid */
        .values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 50px;
        }
        
        .value-item {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .value-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }
        
        .value-icon {
            font-size: 2.5rem;
            color: var(--secondary-color);
            margin-bottom: 20px;
        }
        
        /* Campus Section */
        .campus-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }
        
        .campus-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        .campus-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .campus-header {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }
        
        .campus-header h3 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .campus-content {
            padding: 30px;
        }
        
        .campus-content p {
            color: #666;
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        
        .campus-content i {
            color: var(--secondary-color);
            margin-top: 5px;
        }
        
        /* Formation Grid */
        .formation-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 50px;
        }
        
        .formation-category {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .formation-category h3 {
            color: var(--secondary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        
        .formation-category ul {
            list-style: none;
        }
        
        .formation-category li {
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .formation-category li:last-child {
            border-bottom: none;
        }
        
        .formation-category i {
            color: var(--secondary-color);
            font-size: 0.9rem;
        }
        
        /* Footer */
        footer {
            background-color: var(--primary-color);
            color: white;
            padding: 80px 0 30px;
            margin-top: 80px;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 50px;
        }
        
        .footer-section h3 {
            font-size: 1.5rem;
            margin-bottom: 25px;
            color: white;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-section h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background-color: var(--secondary-color);
        }
        
        .footer-section p {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 20px;
            line-height: 1.7;
        }
        
        .footer-section i {
            color: var(--secondary-color);
            margin-right: 10px;
            width: 20px;
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 15px;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .footer-links a:hover {
            color: var(--secondary-color);
            transform: translateX(5px);
        }
        
        .copyright {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
            }
            
            .page-header h1 {
                font-size: 2.5rem;
            }
            
            .page-header p {
                font-size: 1.1rem;
            }
            
            .section-title {
                font-size: 2.2rem;
            }
            
            .org-level {
                gap: 15px;
            }
            
            .org-position {
                width: 100%;
                max-width: 300px;
            }
        }
        
        @media (max-width: 480px) {
            .page-header h1 {
                font-size: 2rem;
            }
            
            .section-title {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container header-container">
            <div class="logo-container">
                <div style="padding-left: 22px;">
                    <div class="logo-text">IS<span>GI</span></div>
                    <div style="font-size: 0.9rem;color: #666;">Institut Supérieur de Gestion et d'Ingénierie</div>
                </div>
            </div>
            
            <nav class="nav-links">
                <a href="index.php">Accueil</a>
                <a href="inscription.php">Inscription</a>
                <a href="reinscription.php">Réinscription</a>
                <a href="apropos.php" class="active">À propos de nous</a>
                <a href="contact.php">Nous contacter</a>
            </nav>
            
            <div class="auth-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='auth/login.php'">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </button>
                <button class="btn btn-primary" onclick="window.location.href='register_student_tutor.php'">
                    <i class="fas fa-user-plus"></i> Créer un compte
                </button>
            </div>
        </div>
    </header>

    <!-- Page Header -->
    <section class="page-header">
        <div class="container">
            <h1>À propos de l'ISGI</h1>
            <p>Découvrez notre institution, notre mission, notre vision et nos valeurs</p>
        </div>
    </section>

    <!-- Historique et Mission -->
    <section class="content-section">
        <div class="container">
            <h2 class="section-title">Notre Histoire et Mission</h2>
            
            <div class="mission-vision">
                <div class="mission-card">
                    <h3><i class="fas fa-history"></i> Historique</h3>
                    <p>L'Institut Supérieur de Gestion et d'Ingénierie (ISGI) a été créé en 2019 avec l'ambition de devenir une référence incontournable dans la formation professionnelle et académique en Afrique francophone.</p>
                    <p>Dès sa création, ISGI s'est distingué par sa volonté de proposer une formation de qualité, accessible, et tournée vers l'international.</p>
                </div>
                
                <div class="mission-card">
                    <h3><i class="fas fa-bullseye"></i> Notre Mission</h3>
                    <p>Former des cadres compétents, capables de répondre aux exigences du marché du travail local et international.</p>
                    <p>L'institut se positionne comme l'école des entrepreneurs, en mettant l'accent sur l'innovation, le leadership, la polyvalence et l'ouverture au monde.</p>
                </div>
                
                <div class="mission-card">
                    <h3><i class="fas fa-handshake"></i> Partenariats</h3>
                    <p>ISGI est partenaire officiel de plusieurs organismes internationaux reconnus :</p>
                    <ul style="list-style: none; margin-top: 15px;">
                        <li><i class="fas fa-check-circle" style="color: var(--success-color);"></i> City & Guilds de Londres</li>
                        <li><i class="fas fa-check-circle" style="color: var(--success-color);"></i> CSDP</li>
                        <li><i class="fas fa-check-circle" style="color: var(--success-color);"></i> OTHM Qualifications</li>
                        <li><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Horizons University de Paris</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Organigramme -->
    <section class="content-section" style="background-color: #f8f9fa;">
        <div class="container">
            <h2 class="section-title">Organigramme</h2>
            
            <div class="org-chart">
                <!-- Niveau 1: Directeur Général -->
                <div class="org-level">
                    <div class="org-position">
                        <i class="fas fa-user-tie"></i>
                        <h4>Directeur Général (DG)</h4>
                        <p>Représentation légale et orientations stratégiques</p>
                    </div>
                </div>
                
                <!-- Niveau 2 -->
                <div class="org-level">
                    <div class="org-position">
                        <i class="fas fa-users"></i>
                        <h4>Secrétaire Général (Sec. G)</h4>
                        <p>Coordination administrative et gestion des archives</p>
                    </div>
                    
                    <div class="org-position">
                        <i class="fas fa-graduation-cap"></i>
                        <h4>Directeur des Affaires Académiques (DAC)</h4>
                        <p>Supervision pédagogique et organisation des examens</p>
                    </div>
                </div>
                
                <!-- Niveau 3 -->
                <div class="org-level">
                    <div class="org-position">
                        <i class="fas fa-clipboard-check"></i>
                        <h4>Surveillant Général (SG)</h4>
                        <p>Respect de la discipline et suivi de la vie scolaire</p>
                    </div>
                    
                    <div class="org-position">
                        <i class="fas fa-user-secret"></i>
                        <h4>Secrétaire DG (SDG)</h4>
                        <p>Assistance administrative et gestion des correspondances</p>
                    </div>
                    
                    <div class="org-position">
                        <i class="fas fa-user-cog"></i>
                        <h4>Chef de la Scolarité (CS)</h4>
                        <p>Gestion des inscriptions et suivi académique</p>
                    </div>
                </div>
                
                <!-- Niveau 4 -->
                <div class="org-level">
                    <div class="org-position">
                        <i class="fas fa-file-alt"></i>
                        <h4>Secrétaire de la Scolarité (Sec. S)</h4>
                        <p>Gestion des dossiers scolaires et support administratif</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Vision et Valeurs -->
    <section class="content-section">
        <div class="container">
            <h2 class="section-title">Vision et Valeurs</h2>
            
            <div class="mission-vision">
                <div class="vision-card">
                    <h3><i class="fas fa-eye"></i> Notre Vision</h3>
                    <p>La vision de l'ISGI repose sur trois piliers fondamentaux :</p>
                    <ul style="list-style: none; margin-top: 15px;">
                        <li><strong>Excellence académique :</strong> programmes rigoureux, enseignants qualifiés, pédagogie active</li>
                        <li><strong>Innovation pédagogique :</strong> intégration des technologies numériques, projets pratiques, orientation vers l'entrepreneuriat</li>
                        <li><strong>Insertion professionnelle :</strong> stages dès la première année, visites en entreprise, accompagnement vers l'emploi</li>
                    </ul>
                </div>
                
                <div class="values-card">
                    <h3><i class="fas fa-heart"></i> Nos Valeurs</h3>
                    <div class="values-grid">
                        <div class="value-item">
                            <div class="value-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <h4>Intégrité</h4>
                            <p>Dans la gestion administrative et académique</p>
                        </div>
                        
                        <div class="value-item">
                            <div class="value-icon">
                                <i class="fas fa-balance-scale"></i>
                            </div>
                            <h4>Responsabilité</h4>
                            <p>Dans l'accompagnement des étudiants</p>
                        </div>
                        
                        <div class="value-item">
                            <div class="value-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <h4>Transparence</h4>
                            <p>Dans les processus de notation et délivrance des diplômes</p>
                        </div>
                        
                        <div class="value-item">
                            <div class="value-icon">
                                <i class="fas fa-universal-access"></i>
                            </div>
                            <h4>Accessibilité</h4>
                            <p>Campuses répartis et frais adaptés aux réalités locales</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Campus -->
    <section class="content-section" style="background-color: #f8f9fa;">
        <div class="container">
            <h2 class="section-title">Nos Campus</h2>
            <p style="text-align: center; max-width: 800px; margin: 0 auto 50px; color: #666; font-size: 1.1rem;">
                ISGI a choisi de s'implanter dans les trois principales villes du Congo pour réduire les barrières géographiques
            </p>
            
            <div class="campus-grid">
                <div class="campus-card">
                    <div class="campus-header">
                        <h3>Brazzaville</h3>
                        <p>Campus Principal</p>
                    </div>
                    <div class="campus-content">
                        <p><i class="fas fa-map-marker-alt"></i> Quartier Poto-Poto, à proximité de la gare CFCO et de l'avenue de France</p>
                        <p><i class="fas fa-building"></i> Infrastructure moderne avec salles informatiques et laboratoires techniques</p>
                    </div>
                </div>
                
                <div class="campus-card">
                    <div class="campus-header">
                        <h3>Pointe-Noire</h3>
                        <p>Campus Industriel</p>
                    </div>
                    <div class="campus-content">
                        <p><i class="fas fa-map-marker-alt"></i> Quartier Mpita-Socoprise, facilement accessible depuis l'arrêt OCI</p>
                        <p><i class="fas fa-industry"></i> Spécialisé dans les formations industrielles et techniques</p>
                    </div>
                </div>
                
                <div class="campus-card">
                    <div class="campus-header">
                        <h3>Ouesso</h3>
                        <p>Campus du Nord</p>
                    </div>
                    <div class="campus-content">
                        <p><i class="fas fa-map-marker-alt"></i> En plein centre-ville, en diagonale de la CNSS</p>
                        <p><i class="fas fa-users"></i> Formations accessibles pour les étudiants du nord du pays</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Formations -->
    <section class="content-section">
        <div class="container">
            <h2 class="section-title">Nos Formations</h2>
            <p style="text-align: center; max-width: 800px; margin: 0 auto 50px; color: #666; font-size: 1.1rem;">
                Une offre de formation riche et diversifiée répartie en quatre grands pôles
            </p>
            
            <div class="formation-grid">
                <div class="formation-category">
                    <h3>Gestion et Administration</h3>
                    <ul>
                        <li><i class="fas fa-chart-line"></i> Comptabilité et gestion d'entreprise</li>
                        <li><i class="fas fa-search-dollar"></i> Audit et contrôle de gestion</li>
                        <li><i class="fas fa-globe-americas"></i> Commerce international</li>
                        <li><i class="fas fa-coins"></i> Gestion des finances</li>
                        <li><i class="fas fa-users-cog"></i> GRH et administration</li>
                        <li><i class="fas fa-bullhorn"></i> Marketing et communication</li>
                        <li><i class="fas fa-lightbulb"></i> Entrepreneuriat et leadership</li>
                        <li><i class="fas fa-truck"></i> Logistique et transport</li>
                    </ul>
                </div>
                
                <div class="formation-category">
                    <h3>Technologie</h3>
                    <ul>
                        <li><i class="fas fa-network-wired"></i> Réseaux informatiques</li>
                        <li><i class="fas fa-satellite-dish"></i> Télécommunications</li>
                        <li><i class="fas fa-tools"></i> Maintenance informatique</li>
                        <li><i class="fas fa-code"></i> Développement web et mobile</li>
                        <li><i class="fas fa-laptop-code"></i> Génie logiciel</li>
                        <li><i class="fas fa-robot"></i> Robotique et IA</li>
                        <li><i class="fas fa-shield-alt"></i> Sécurité informatique</li>
                        <li><i class="fas fa-database"></i> Administration des bases de données</li>
                    </ul>
                </div>
                
                <div class="formation-category">
                    <h3>Industrie</h3>
                    <ul>
                        <li><i class="fas fa-bolt"></i> Génie électrique et électronique</li>
                        <li><i class="fas fa-cogs"></i> Génie mécanique</li>
                        <li><i class="fas fa-hard-hat"></i> Génie civil et architecture</li>
                        <li><i class="fas fa-oil-can"></i> Maintenance pétrolière et gazière</li>
                        <li><i class="fas fa-solar-panel"></i> Énergies renouvelables</li>
                        <li><i class="fas fa-ruler-combined"></i> Topographie</li>
                        <li><i class="fas fa-clipboard-check"></i> QHSE</li>
                    </ul>
                </div>
                
                <div class="formation-category">
                    <h3>Droit</h3>
                    <ul>
                        <li><i class="fas fa-balance-scale"></i> Droit civil</li>
                        <li><i class="fas fa-gavel"></i> Droit commercial et immobilier</li>
                        <li><i class="fas fa-briefcase"></i> Droit des affaires</li>
                        <li><i class="fas fa-globe"></i> Droit international</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>ISGI</h3>
                    <p>Institut Supérieur de Gestion et d'Ingénierie, formant les leaders de demain.</p>
                    <div style="margin-top: 15px;">
                        <div><i class="fas fa-map-marker-alt"></i> Brazzaville, Congo</div>
                        <div><i class="fas fa-phone"></i> +242 06 848 45 67</div>
                        <div><i class="fas fa-envelope"></i> contact@isgi.cg</div>
                    </div>
                </div>
                
                <div class="footer-section">
                    <h3>Liens rapides</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="inscription.php">Inscription</a></li>
                        <li><a href="reinscription.php">Réinscription</a></li>
                        <li><a href="bibliotheque.php">Bibliothèque</a></li>
                        <li><a href="apropos.php">À propos de nous</a></li>
                        <li><a href="contact.php">Nous contacter</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3>Domaines</h3>
                    <ul class="footer-links">
                        <li><a href="#">Technologies</a></li>
                        <li><a href="#">Gestion</a></li>
                        <li><a href="#">Droit</a></li>
                        <li><a href="#">Industrie</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                &copy; 2025 ISGI - Institut Supérieur de Gestion et d'Ingénierie. Tous droits réservés.
            </div>
        </div>
    </footer>

    <script>
        // Animation simple pour les éléments
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            // Observer les cartes
            document.querySelectorAll('.mission-card, .vision-card, .values-card, .campus-card, .formation-category, .org-position, .value-item').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
            
            // Déclencher l'animation après un délai
            setTimeout(() => {
                document.querySelectorAll('.mission-card, .vision-card, .values-card, .campus-card, .formation-category, .org-position, .value-item').forEach(card => {
                    if (card.getBoundingClientRect().top < window.innerHeight) {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }
                });
            }, 100);
        });
    </script>
</body>
</html>