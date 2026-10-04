<?php
session_start();
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Récupérer les statistiques
$stats = [];
try {
    $stmt = $db->query("SELECT 
        (SELECT COUNT(*) FROM etudiants WHERE statut = 'valide') as etudiants_valides,
        (SELECT COUNT(*) FROM filieres WHERE est_actif = 1) as filieres_actives,
        (SELECT valeur FROM configurations WHERE cle = 'annee_academique') as annee_academique");
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    logError("Erreur récupération stats: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISGI - Accueil</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
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
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }
        
        /* Header */
        .navbar-brand {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color) !important;
            display: flex;
            align-items: center;
        }
        
        .navbar-brand img.logo {
            height: 60px;
            margin-right: 15px;
        }
        
        .navbar-brand .logo-text {
            display: flex;
            flex-direction: column;
        }
        
        .navbar-brand span {
            color: var(--secondary-color);
        }
        
        .brand-subtitle {
            font-size: 0.9rem;
            color: #666;
            font-weight: normal;
            margin-top: 2px;
        }
        
        .nav-link {
            font-weight: 500;
            color: var(--dark-color) !important;
            position: relative;
            padding: 8px 0 !important;
            margin: 0 10px;
        }
        
        .nav-link:hover {
            color: var(--secondary-color) !important;
        }
        
        .nav-link.active {
            color: var(--secondary-color) !important;
            font-weight: 600;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--secondary-color);
            transition: width 0.3s;
        }
        
        .nav-link:hover::after,
        .nav-link.active::after {
            width: 100%;
        }
        
        .btn-isgi-primary {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-isgi-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            color: white;
        }
        
        .btn-isgi-secondary {
            background-color: var(--light-color);
            color: var(--dark-color);
            border: 1px solid #ddd;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-isgi-secondary:hover {
            background-color: #d5dbdb;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-isgi-success {
            background-color: var(--success-color);
            color: white;
            border: none;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-isgi-success:hover {
            background-color: #219653;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            color: white;
        }
        
        .btn-large {
            padding: 15px 40px !important;
            font-size: 1.1rem;
        }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 120px 0 80px;
            text-align: center;
            margin-top: 0;
        }
        
        .hero h1 {
            font-size: 3.5rem;
            margin-bottom: 20px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        /* Stats Section */
        .stat-card {
            background: white;
            padding: 40px 25px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--secondary-color);
        }
        
        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .stat-icon {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 20px;
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            color: var(--primary-color);
            margin: 10px 0;
        }
        
        /* Improved Carousel Styles */
        .carousel-section {
            padding: 80px 0;
        }
        
        .circular-carousel-container {
            position: relative;
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .circular-carousel-track {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 500px;
            position: relative;
            perspective: 1000px;
        }
        
        .circular-carousel-item {
            position: absolute;
            width: 300px;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0.6;
            transform-style: preserve-3d;
        }
        
        .circular-carousel-item.center {
            transform: translateX(0) scale(1.2) rotateY(0deg);
            z-index: 10;
            opacity: 1;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
        }
        
        .circular-carousel-item.left {
            transform: translateX(-80%) scale(0.9) rotateY(-30deg);
            z-index: 5;
        }
        
        .circular-carousel-item.right {
            transform: translateX(80%) scale(0.9) rotateY(30deg);
            z-index: 5;
        }
        
        .circular-carousel-item.hidden-left {
            transform: translateX(-120%) scale(0.8) rotateY(-40deg);
            opacity: 0.3;
            z-index: 1;
        }
        
        .circular-carousel-item.hidden-right {
            transform: translateX(120%) scale(0.8) rotateY(40deg);
            opacity: 0.3;
            z-index: 1;
        }
        
        .circular-carousel-image {
            height: 200px;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .circular-carousel-image i {
            font-size: 4rem;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .circular-carousel-content {
            padding: 25px;
        }
        
        .circular-carousel-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .circular-carousel-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--secondary-color);
            color: white;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .circular-carousel-btn:hover {
            background: #2980b9;
            transform: translateY(-50%) scale(1.1);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }
        
        .circular-carousel-btn.prev {
            left: 20px;
        }
        
        .circular-carousel-btn.next {
            right: 20px;
        }
        
        .circular-carousel-indicators {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 40px;
        }
        
        .circular-carousel-indicator {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #ddd;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .circular-carousel-indicator.active {
            background: var(--secondary-color);
            transform: scale(1.3);
            box-shadow: 0 0 10px rgba(52, 152, 219, 0.5);
        }
        
        .circular-carousel-indicator::after {
            content: '';
            position: absolute;
            top: -4px;
            left: -4px;
            right: -4px;
            bottom: -4px;
            border-radius: 50%;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .circular-carousel-indicator.active::after {
            border-color: var(--secondary-color);
        }
        
        /* YouTube Tutorials Section */
        .youtube-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .youtube-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .youtube-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .youtube-thumbnail {
            height: 200px;
            background: #ff0000;
            position: relative;
            overflow: hidden;
        }
        
        .youtube-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .youtube-card:hover .youtube-thumbnail img {
            transform: scale(1.05);
        }
        
        .youtube-play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 70px;
            height: 50px;
            background: rgba(255, 0, 0, 0.9);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .youtube-play-btn:hover {
            background: #cc0000;
            transform: translate(-50%, -50%) scale(1.1);
            color: white;
        }
        
        .youtube-content {
            padding: 25px;
        }
        
        .youtube-meta {
            display: flex;
            justify-content: space-between;
            color: #666;
            font-size: 0.9rem;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        
        /* Testimonials Section */
        .testimonials-section {
            padding: 80px 0;
            background: var(--primary-color);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .testimonials-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--secondary-color), var(--accent-color));
        }
        
        .testimonial-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .testimonial-card:hover {
            transform: translateY(-10px);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }
        
        .testimonial-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .testimonial-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--accent-color) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 1.8rem;
            color: white;
            font-weight: bold;
        }
        
        .testimonial-info h4 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .testimonial-info p {
            margin: 5px 0 0;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
        }
        
        .testimonial-rating {
            color: #FFD700;
            margin: 10px 0;
        }
        
        .testimonial-text {
            font-style: italic;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .testimonial-quote {
            font-size: 3rem;
            color: var(--secondary-color);
            opacity: 0.3;
            position: absolute;
            top: 20px;
            right: 30px;
        }
        
        /* Library Section */
        .library-section {
            padding: 80px 0;
            background-color: white;
        }
        
        .library-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid #eee;
            height: 100%;
        }
        
        .library-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .library-image {
            height: 200px;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-color) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .library-image i {
            font-size: 5rem;
            color: rgba(255, 255, 255, 0.9);
        }
        
        .library-content {
            padding: 30px;
        }
        
        .library-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        /* Actions Section */
        .action-card {
            background: white;
            padding: 45px 35px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        
        .action-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .action-icon {
            font-size: 3.5rem;
            color: var(--secondary-color);
            margin-bottom: 25px;
        }
        
        /* Campus Services Grid */
        .campus-services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }
        
        .service-item {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 4px solid var(--secondary-color);
        }
        
        .service-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border-left-color: var(--accent-color);
        }
        
        .service-icon {
            font-size: 2rem;
            color: var(--secondary-color);
            margin-bottom: 15px;
        }
        
        .service-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .service-description {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        /* Campus Universitaire Section */
        .campus-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }
        
        .campus-header {
            text-align: center;
            margin-bottom: 50px;
        }
        
        .campus-badge {
            display: inline-block;
            background: var(--secondary-color);
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .campus-feature {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
            text-align: center;
        }
        
        .campus-feature:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .campus-feature-icon {
            font-size: 3rem;
            color: var(--secondary-color);
            margin-bottom: 20px;
            height: 80px;
            width: 80px;
            background: linear-gradient(135deg, #f0f7ff 0%, #e3f2fd 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
        }
        
        .campus-feature-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 15px;
        }
        
        .campus-feature-list {
            text-align: left;
            margin-top: 20px;
        }
        
        .campus-feature-list li {
            margin-bottom: 10px;
            color: #666;
            display: flex;
            align-items: center;
        }
        
        .campus-feature-list li i {
            color: var(--success-color);
            margin-right: 10px;
            font-size: 0.9rem;
        }
        
        /* Cours en ligne Section */
        .courses-section {
            padding: 80px 0;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .course-item {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .course-item:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }
        
        .course-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: var(--accent-color);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .course-info {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-top: 1px solid #eee;
        }
        
        /* Section Titles */
        .section-title {
            font-size: 2.8rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 50px;
            color: var(--primary-color);
            position: relative;
            display: inline-block;
            left: 50%;
            transform: translateX(-50%);
        }
        
        .section-title.white {
            color: white;
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
        
        .section-title.white::after {
            background: white;
        }
        
        /* Footer */
        footer {
            background-color: var(--primary-color);
            color: white;
            padding: 80px 0 30px;
            margin-top: 80px;
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
        
        .footer-links {
            list-style: none;
            padding-left: 0;
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
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .circular-carousel-track {
                height: 450px;
            }
            
            .circular-carousel-item {
                width: 280px;
            }
        }
        
        @media (max-width: 992px) {
            .circular-carousel-track {
                height: 400px;
            }
            
            .circular-carousel-item {
                width: 250px;
            }
            
            .circular-carousel-item.left {
                transform: translateX(-70%) scale(0.9);
            }
            
            .circular-carousel-item.right {
                transform: translateX(70%) scale(0.9);
            }
            
            .campus-services-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2.5rem;
            }
            
            .section-title {
                font-size: 2.2rem;
            }
            
            .stat-card,
            .action-card {
                padding: 30px 20px;
            }
            
            .stat-number {
                font-size: 2.5rem;
            }
            
            .action-icon {
                font-size: 3rem;
            }
            
            .navbar-brand img.logo {
                height: 50px;
                margin-right: 10px;
            }
            
            .circular-carousel-track {
                height: 350px;
            }
            
            .circular-carousel-item {
                width: 220px;
            }
            
            .circular-carousel-btn {
                width: 50px;
                height: 50px;
                font-size: 1.2rem;
            }
            
            .testimonial-card {
                padding: 30px;
            }
            
            .campus-services-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .hero h1 {
                font-size: 2rem;
            }
            
            .btn-large {
                padding: 12px 30px !important;
            }
            
            .navbar-brand {
                font-size: 24px;
            }
            
            .navbar-brand img.logo {
                height: 40px;
            }
            
            .circular-carousel-track {
                height: 300px;
            }
            
            .circular-carousel-item {
                width: 200px;
            }
            
            .circular-carousel-btn {
                width: 40px;
                height: 40px;
                font-size: 1rem;
                left: 10px;
            }
            
            .circular-carousel-btn.next {
                right: 10px;
            }
            
            .testimonial-header {
                flex-direction: column;
                text-align: center;
            }
            
            .testimonial-avatar {
                margin-right: 0;
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Header avec Bootstrap Navbar -->
    <header>
        <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top">
            <div class="container">
                <!-- Logo avec image -->
                <a class="navbar-brand" href="index.php">
                    <!-- Remplacez le src par le chemin de votre logo -->
                    <img src="image/logo isgi.jpg" alt="ISGI Logo" class="logo" onerror="this.onerror=null; this.style.display='none';">
                    <div class="logo-text">
                        <span>ISGI</span>
                        <div class="brand-subtitle">Institut Supérieur de Gestion et d'Ingénierie</div>
                    </div>
                </a>
                
                <!-- Mobile Toggle Button -->
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                        aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                
                <!-- Navigation Links -->
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav mx-auto">
                        <li class="nav-item">
                            <a class="nav-link active" href="index.php">Accueil</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="inscription.php">Inscription</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reinscription.php">Réinscription</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="apropos.php">À propos </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="contact.php">Nous contacter</a>
                        </li>
                    </ul>
                    
                    <!-- Auth Buttons -->
                    <div class="d-flex flex-column flex-lg-row gap-2">
                        <button class="btn btn-isgi-secondary" onclick="window.location.href='auth/login.php'">
                            <i class="fas fa-sign-in-alt"></i> Se connecter
                        </button>
                        <button class="btn btn-isgi-primary" onclick="window.location.href='register_student_tutor.php'">
                            <i class="fas fa-user-plus"></i> Créer un compte
                        </button>
                    </div>
                </div>
            </div>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero" style="padding-top: 140px;">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <h1 class="mb-4">Bienvenue à l'ISGI</h1>
                    <p class="lead mb-4">Institut Supérieur de Gestion et d'Ingénierie - Formons les leaders de demain avec excellence et innovation</p>
                    <p class="h5 mb-5">Année académique : <strong><?php echo htmlspecialchars($stats['annee_academique'] ?? '2025-2026'); ?></strong></p>
                    <div class="mt-4">
                        <button class="btn btn-isgi-primary btn-large" onclick="window.location.href='inscription.php'">
                            <i class="fas fa-user-graduate"></i> Commencer votre inscription
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-5">
        <div class="container">
            <h2 class="section-title mb-5">Notre Institution en Chiffres</h2>
            
            <div class="row g-4">
                <!-- Étudiants -->
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="stat-number"><?php echo htmlspecialchars($stats['etudiants_valides'] ?? '0'); ?>+</div>
                        <h3 class="h4">Étudiants</h3>
                        <p class="text-muted">Étudiants actuellement formés</p>
                    </div>
                </div>
                
                <!-- Filières -->
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="stat-number"><?php echo htmlspecialchars($stats['filieres_actives'] ?? '12'); ?></div>
                        <h3 class="h4">Filières</h3>
                        <p class="text-muted">Programmes d'excellence</p>
                    </div>
                </div>
                
                <!-- Campus -->
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="stat-number">3</div>
                        <h3 class="h4">Campus</h3>
                        <p class="text-muted">Brazzaville, Pointe-Noire, Ouesso</p>
                    </div>
                </div>
                
                <!-- Partenaires -->
                <div class="col-md-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-globe"></i>
                        </div>
                        <div class="stat-number">4</div>
                        <h3 class="h4">Partenaires</h3>
                        <p class="text-muted">International</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Bibliothèque Section avec Carrousel Circulaire -->
    <section id="bibliotheque" class="library-section">
        <div class="container">
            <h2 class="section-title mb-4">Bibliothèque Virtuelle</h2>
            <p class="text-center text-muted mb-5 mx-auto" style="max-width: 800px; font-size: 1.1rem;">
                Explorez notre collection de ressources académiques avec notre carrousel circulaire interactif
            </p>
            
            <!-- Bibliothèque Carrousel Circulaire -->
            <div class="circular-carousel-container">
                <button class="circular-carousel-btn prev" onclick="moveCircularCarousel('library', -1)">
                    <i class="fas fa-chevron-left"></i>
                </button>
                
                <div class="circular-carousel-track" id="libraryCarouselTrack">
                    <!-- Les éléments seront positionnés par JavaScript -->
                    <div class="circular-carousel-item" data-index="0">
                        <div class="circular-carousel-image">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <h4 class="circular-carousel-title">Livres Numériques</h4>
                            <p class="text-muted">Plus de 10,000 livres couvrant tous les domaines de l'ingénierie et de la gestion.</p>
                            <div class="course-info">
                                <span><i class="fas fa-book me-1"></i> 10K+</span>
                                <span><i class="fas fa-tag me-1"></i> 50+ Catégories</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="1">
                        <div class="circular-carousel-image">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <h4 class="circular-carousel-title">Publications Scientifiques</h4>
                            <p class="text-muted">Collections complètes de journaux scientifiques et articles de recherche.</p>
                            <div class="course-info">
                                <span><i class="fas fa-newspaper me-1"></i> 5K+ Articles</span>
                                <span><i class="fas fa-journal-whills me-1"></i> 100+ Journaux</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="2">
                        <div class="circular-carousel-image">
                            <i class="fas fa-video"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <h4 class="circular-carousel-title">Ressources Multimédias</h4>
                            <p class="text-muted">Cours vidéo, conférences enregistrées et tutoriels interactifs.</p>
                            <div class="course-info">
                                <span><i class="fas fa-play-circle me-1"></i> 1K+ Vidéos</span>
                                <span><i class="fas fa-chalkboard-teacher me-1"></i> 200+ Cours</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="3">
                        <div class="circular-carousel-image">
                            <i class="fas fa-database"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <h4 class="circular-carousel-title">Bases de Données</h4>
                            <p class="text-muted">Accès aux principales bases de données académiques et scientifiques.</p>
                            <div class="course-info">
                                <span><i class="fas fa-server me-1"></i> 20+ Bases</span>
                                <span><i class="fas fa-globe me-1"></i> International</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="4">
                        <div class="circular-carousel-image">
                            <i class="fas fa-theater-masks"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <h4 class="circular-carousel-title">Cas Pratiques</h4>
                            <p class="text-muted">Études de cas et simulations pour une approche pratique.</p>
                            <div class="course-info">
                                <span><i class="fas fa-briefcase me-1"></i> 500+ Cas</span>
                                <span><i class="fas fa-industry me-1"></i> Réels</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button class="circular-carousel-btn next" onclick="moveCircularCarousel('library', 1)">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            
            <!-- Indicateurs -->
            <div class="circular-carousel-indicators" id="libraryIndicators">
                <!-- Les indicateurs seront générés par JavaScript -->
            </div>
            
            <div class="text-center mt-5">
                <button class="btn btn-isgi-primary btn-large" onclick="window.location.href='bibliotheque.php'">
                    <i class="fas fa-book-open"></i> Accéder à la Bibliothèque Complète
                </button>
            </div>
        </div>
    </section>

    <!-- Cours en Ligne Section avec Carrousel Circulaire -->
    <section class="courses-section">
        <div class="container">
            <h2 class="section-title mb-4">Cours en Ligne</h2>
            <p class="text-center text-muted mb-5 mx-auto" style="max-width: 800px; font-size: 1.1rem;">
                Parcourez nos cours en ligne avec notre carrousel circulaire interactif
            </p>
            
            <!-- Cours Carrousel Circulaire -->
            <div class="circular-carousel-container">
                <button class="circular-carousel-btn prev" onclick="moveCircularCarousel('courses', -1)">
                    <i class="fas fa-chevron-left"></i>
                </button>
                
                <div class="circular-carousel-track" id="coursesCarouselTrack">
                    <!-- Les éléments seront positionnés par JavaScript -->
                    <div class="circular-carousel-item" data-index="0">
                        <div class="circular-carousel-image">
                            <i class="fas fa-laptop-code"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <div class="course-badge">Nouveau</div>
                            <h4 class="circular-carousel-title">Développement Web</h4>
                            <p class="text-muted">Maîtrisez HTML, CSS, JavaScript et les frameworks modernes pour créer des applications web.</p>
                            <div class="course-info">
                                <span><i class="fas fa-clock me-1"></i> 60h</span>
                                <span><i class="fas fa-signal me-1"></i> Avancé</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="1">
                        <div class="circular-carousel-image">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <div class="course-badge">Populaire</div>
                            <h4 class="circular-carousel-title">Data Science</h4>
                            <p class="text-muted">Apprenez l'analyse de données, le machine learning et la visualisation avec Python.</p>
                            <div class="course-info">
                                <span><i class="fas fa-clock me-1"></i> 80h</span>
                                <span><i class="fas fa-signal me-1"></i> Intermédiaire</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="2">
                        <div class="circular-carousel-image">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <div class="course-badge">Essentiel</div>
                            <h4 class="circular-carousel-title">Cybersécurité</h4>
                            <p class="text-muted">Protégez les systèmes informatiques contre les cyberattaques et les menaces.</p>
                            <div class="course-info">
                                <span><i class="fas fa-clock me-1"></i> 70h</span>
                                <span><i class="fas fa-signal me-1"></i> Avancé</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="3">
                        <div class="circular-carousel-image">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <div class="course-badge">IA</div>
                            <h4 class="circular-carousel-title">Intelligence Artificielle</h4>
                            <p class="text-muted">Découvrez les fondamentaux de l'IA, du deep learning et des réseaux neuronaux.</p>
                            <div class="course-info">
                                <span><i class="fas fa-clock me-1"></i> 90h</span>
                                <span><i class="fas fa-signal me-1"></i> Expert</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="4">
                        <div class="circular-carousel-image">
                            <i class="fas fa-cloud"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <div class="course-badge">Cloud</div>
                            <h4 class="circular-carousel-title">Cloud Computing</h4>
                            <p class="text-muted">Maîtrisez AWS, Azure et Google Cloud pour le déploiement d'applications.</p>
                            <div class="course-info">
                                <span><i class="fas fa-clock me-1"></i> 65h</span>
                                <span><i class="fas fa-signal me-1"></i> Intermédiaire</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="circular-carousel-item" data-index="5">
                        <div class="circular-carousel-image">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <div class="circular-carousel-content">
                            <div class="course-badge">Mobile</div>
                            <h4 class="circular-carousel-title">Développement Mobile</h4>
                            <p class="text-muted">Créez des applications iOS et Android avec React Native et Flutter.</p>
                            <div class="course-info">
                                <span><i class="fas fa-clock me-1"></i> 75h</span>
                                <span><i class="fas fa-signal me-1"></i> Débutant</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button class="circular-carousel-btn next" onclick="moveCircularCarousel('courses', 1)">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            
            <!-- Indicateurs -->
            <div class="circular-carousel-indicators" id="coursesIndicators">
                <!-- Les indicateurs seront générés par JavaScript -->
            </div>
            
            <div class="text-center mt-5">
                <button class="btn btn-isgi-success btn-large" onclick="window.location.href='cours-en-ligne.php'">
                    <i class="fas fa-play-circle"></i> Voir Tous les Cours
                </button>
            </div>
        </div>
    </section>

    <!-- Tutoriels YouTube Section -->
    <section class="youtube-section">
        <div class="container">
            <h2 class="section-title mb-4">Tutoriels d'Apprentissage</h2>
            <p class="text-center text-muted mb-5 mx-auto" style="max-width: 800px; font-size: 1.1rem;">
                Découvrez nos tutoriels vidéo sur YouTube pour compléter votre formation
            </p>
            
            <div class="row g-4">
                <!-- Tutoriel 1 -->
                <div class="col-lg-4 col-md-6">
                    <div class="youtube-card">
                        <div class="youtube-thumbnail">
                            <img src="https://img.youtube.com/vi/hdI2bqOjy3c/maxresdefault.jpg" alt="Introduction à la programmation">
                            <a href="https://www.youtube.com/watch?v=hdI2bqOjy3c" class="youtube-play-btn" target="_blank">
                                <i class="fas fa-play"></i>
                            </a>
                        </div>
                        <div class="youtube-content">
                            <h4 class="h4 mb-3">Introduction à la Programmation</h4>
                            <p class="text-muted">Apprenez les bases de la programmation avec Python. Tutoriel complet pour débutants.</p>
                            <div class="youtube-meta">
                                <span><i class="fas fa-clock me-1"></i> 45 min</span>
                                <span><i class="fas fa-eye me-1"></i> 25K vues</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tutoriel 2 -->
                <div class="col-lg-4 col-md-6">
                    <div class="youtube-card">
                        <div class="youtube-thumbnail">
                            <img src="https://img.youtube.com/vi/BWXggB-T1jQ/maxresdefault.jpg" alt="Base de données SQL">
                            <a href="https://www.youtube.com/watch?v=BWXggB-T1jQ" class="youtube-play-btn" target="_blank">
                                <i class="fas fa-play"></i>
                            </a>
                        </div>
                        <div class="youtube-content">
                            <h4 class="h4 mb-3">Base de Données SQL</h4>
                            <p class="text-muted">Maîtrisez les fondamentaux des bases de données relationnelles avec MySQL.</p>
                            <div class="youtube-meta">
                                <span><i class="fas fa-clock me-1"></i> 60 min</span>
                                <span><i class="fas fa-eye me-1"></i> 18K vues</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tutoriel 3 -->
                <div class="col-lg-4 col-md-6">
                    <div class="youtube-card">
                        <div class="youtube-thumbnail">
                            <img src="https://img.youtube.com/vi/8aGhZQkoFbQ/maxresdefault.jpg" alt="JavaScript moderne">
                            <a href="https://www.youtube.com/watch?v=8aGhZQkoFbQ" class="youtube-play-btn" target="_blank">
                                <i class="fas fa-play"></i>
                            </a>
                        </div>
                        <div class="youtube-content">
                            <h4 class="h4 mb-3">JavaScript Moderne</h4>
                            <p class="text-muted">Découvrez les dernières fonctionnalités de JavaScript ES6+ avec des exemples pratiques.</p>
                            <div class="youtube-meta">
                                <span><i class="fas fa-clock me-1"></i> 55 min</span>
                                <span><i class="fas fa-eye me-1"></i> 32K vues</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-5">
                <a href="https://www.youtube.com/channel/UC-exemple" class="btn btn-isgi-primary btn-large" target="_blank">
                    <i class="fab fa-youtube"></i> Voir Plus de Tutoriels
                </a>
            </div>
        </div>
    </section>

    <!-- Témoignages des Étudiants -->
    <section class="testimonials-section">
        <div class="container">
            <h2 class="section-title white mb-4">Témoignages des Étudiants</h2>
            <p class="text-center text-white mb-5 mx-auto" style="max-width: 800px; font-size: 1.1rem; opacity: 0.9;">
                Découvrez ce que nos étudiants disent de leur expérience à l'ISGI
            </p>
            
            <div class="row g-4">
                <!-- Témoignage 1 -->
                <div class="col-lg-4 col-md-6">
                    <div class="testimonial-card">
                        <div class="testimonial-quote">"</div>
                        <div class="testimonial-header">
                            <div class="testimonial-avatar">MJ</div>
                            <div class="testimonial-info">
                                <h4>Marie Joseph</h4>
                                <p>Étudiante en Génie Logiciel</p>
                            </div>
                        </div>
                        <div class="testimonial-rating">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                        </div>
                        <p class="testimonial-text">
                            "L'ISGI m'a offert une formation d'excellence avec des enseignants disponibles et un accompagnement personnalisé. Les projets pratiques m'ont permis d'acquérir une expérience concrète."
                        </p>
                    </div>
                </div>
                
                <!-- Témoignage 2 -->
                <div class="col-lg-4 col-md-6">
                    <div class="testimonial-card">
                        <div class="testimonial-quote">"</div>
                        <div class="testimonial-header">
                            <div class="testimonial-avatar">PK</div>
                            <div class="testimonial-info">
                                <h4>Paul Kibwe</h4>
                                <p>Étudiant en Data Science</p>
                            </div>
                        </div>
                        <div class="testimonial-rating">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star-half-alt"></i>
                        </div>
                        <p class="testimonial-text">
                            "La qualité des enseignements et les équipements modernes de l'ISGI sont exceptionnels. J'ai pu travailler sur des projets réels avec des entreprises partenaires."
                        </p>
                    </div>
                </div>
                
                <!-- Témoignage 3 -->
                <div class="col-lg-4 col-md-6">
                    <div class="testimonial-card">
                        <div class="testimonial-quote">"</div>
                        <div class="testimonial-header">
                            <div class="testimonial-avatar">AN</div>
                            <div class="testimonial-info">
                                <h4>Anna N'Goma</h4>
                                <p>Étudiante en Gestion de Projet</p>
                            </div>
                        </div>
                        <div class="testimonial-rating">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                        </div>
                        <p class="testimonial-text">
                            "L'approche pratique de l'ISGI est remarquable. Les études de cas et les simulations en entreprise m'ont parfaitement préparée au monde professionnel."
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-5">
                <button class="btn btn-isgi-secondary btn-large" onclick="window.location.href='temoignages.php'">
                    <i class="fas fa-comments"></i> Voir Plus de Témoignages
                </button>
            </div>
        </div>
    </section>

    <!-- Campus Universitaire Section -->
    <section class="campus-section">
        <div class="container">
            <div class="campus-header">
                <span class="campus-badge">Campus Universitaire</span>
                <h2 class="section-title mb-3">Nos Services sur le Campus</h2>
                <p class="text-muted mx-auto" style="max-width: 800px; font-size: 1.1rem;">
                    Découvrez les infrastructures et services modernes de notre campus pour une expérience étudiante complète et confortable
                </p>
            </div>
            
            <div class="row g-4">
                <!-- Hébergement et Logement -->
                <div class="col-lg-4 col-md-6">
                    <div class="campus-feature">
                        <div class="campus-feature-icon">
                            <i class="fas fa-home"></i>
                        </div>
                        <h3 class="campus-feature-title">Hébergement & Logement</h3>
                        <p class="text-muted">Résidences étudiantes modernes et confortables pour un séjour agréable</p>
                        <ul class="campus-feature-list">
                            <li><i class="fas fa-check"></i> Chambres individuelles et partagées</li>
                            <li><i class="fas fa-check"></i> Accès internet haute vitesse</li>
                            <li><i class="fas fa-check"></i> Espaces communs équipés</li>
                            <li><i class="fas fa-check"></i> Sécurité 24h/24</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Restauration et Nutrition -->
                <div class="col-lg-4 col-md-6">
                    <div class="campus-feature">
                        <div class="campus-feature-icon">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <h3 class="campus-feature-title">Restauration & Nutrition</h3>
                        <p class="text-muted">Services de restauration variés et équilibrés pour tous les goûts</p>
                        <ul class="campus-feature-list">
                            <li><i class="fas fa-check"></i> Restaurant universitaire</li>
                            <li><i class="fas fa-check"></i> Cafétéria et snacks</li>
                            <li><i class="fas fa-check"></i> Cuisine internationale</li>
                            <li><i class="fas fa-check"></i> Options végétariennes</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Services Sanitaires -->
                <div class="col-lg-4 col-md-6">
                    <div class="campus-feature">
                        <div class="campus-feature-icon">
                            <i class="fas fa-shower"></i>
                        </div>
                        <h3 class="campus-feature-title">Services Sanitaires</h3>
                        <p class="text-muted">Infrastructures sanitaires modernes et bien entretenues</p>
                        <ul class="campus-feature-list">
                            <li><i class="fas fa-check"></i> Toilettes modernes</li>
                            <li><i class="fas fa-check"></i> Douches individuelles</li>
                            <li><i class="fas fa-check"></i> Espaces de détente</li>
                            <li><i class="fas fa-check"></i> Nettoyage quotidien</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Santé et Bien-être -->
                <div class="col-lg-4 col-md-6">
                    <div class="campus-feature">
                        <div class="campus-feature-icon">
                            <i class="fas fa-hospital"></i>
                        </div>
                        <h3 class="campus-feature-title">Santé & Bien-être</h3>
                        <p class="text-muted">Services médicaux et de bien-être pour les étudiants</p>
                        <ul class="campus-feature-list">
                            <li><i class="fas fa-check"></i> Infirmerie universitaire</li>
                            <li><i class="fas fa-check"></i> Services médicaux d'urgence</li>
                            <li><i class="fas fa-check"></i> Centre de bien-être</li>
                            <li><i class="fas fa-check"></i> Counseling psychologique</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Loisirs et Divertissement -->
                <div class="col-lg-4 col-md-6">
                    <div class="campus-feature">
                        <div class="campus-feature-icon">
                            <i class="fas fa-gamepad"></i>
                        </div>
                        <h3 class="campus-feature-title">Loisirs & Divertissement</h3>
                        <p class="text-muted">Espaces de détente et activités récréatives</p>
                        <ul class="campus-feature-list">
                            <li><i class="fas fa-check"></i> Salle de jeux et divertissement</li>
                            <li><i class="fas fa-check"></i> Espace multimédias</li>
                            <li><i class="fas fa-check"></i> Terrain de sport</li>
                            <li><i class="fas fa-check"></i> Club des langues vivantes</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Ateliers et Apprentissage -->
                <div class="col-lg-4 col-md-6">
                    <div class="campus-feature">
                        <div class="campus-feature-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <h3 class="campus-feature-title">Ateliers & Apprentissage</h3>
                        <p class="text-muted">Espaces dédiés à la pratique et à l'innovation</p>
                        <ul class="campus-feature-list">
                            <li><i class="fas fa-check"></i> Ateliers techniques</li>
                            <li><i class="fas fa-check"></i> Laboratoires spécialisés</li>
                            <li><i class="fas fa-check"></i> Espaces de coworking</li>
                            <li><i class="fas fa-check"></i> Salles de projet</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Grille détaillée des services -->
            <div class="mt-5">
                <h3 class="h4 text-center mb-4">Tous Nos Services Campus</h3>
                <div class="campus-services-grid">
                    <!-- Logement -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-bed"></i>
                        </div>
                        <h4 class="service-title">Logement Étudiant</h4>
                        <p class="service-description">Résidences confortables avec espaces communs, cuisine et laverie.</p>
                    </div>
                    
                    <!-- Restaurant -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-utensil-spoon"></i>
                        </div>
                        <h4 class="service-title">Restaurant Universitaire</h4>
                        <p class="service-description">Repas équilibrés à prix étudiants avec options variées.</p>
                    </div>
                    
                    <!-- Toilettes -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-restroom"></i>
                        </div>
                        <h4 class="service-title">Toilettes Modernes</h4>
                        <p class="service-description">Sanitaires propres et accessibles dans tout le campus.</p>
                    </div>
                    
                    <!-- Douches -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-shower"></i>
                        </div>
                        <h4 class="service-title">Douches Individuelles</h4>
                        <p class="service-description">Espaces de douche privés avec eau chaude permanente.</p>
                    </div>
                    
                    <!-- Cuisine -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-warehouse"></i>
                        </div>
                        <h4 class="service-title">Cuisine Équipée</h4>
                        <p class="service-description">Espaces cuisine communs équipés pour les étudiants résidents.</p>
                    </div>
                    
                    <!-- Hôpital -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-clinic-medical"></i>
                        </div>
                        <h4 class="service-title">Infirmerie Universitaire</h4>
                        <p class="service-description">Soins médicaux de base et orientation vers les hôpitaux partenaires.</p>
                    </div>
                    
                    <!-- Services sanitaires -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-hand-sparkles"></i>
                        </div>
                        <h4 class="service-title">Services Sanitaires</h4>
                        <p class="service-description">Hygiène et propreté garanties dans toutes les infrastructures.</p>
                    </div>
                    
                    <!-- Salubrité -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-broom"></i>
                        </div>
                        <h4 class="service-title">Services de Salubrité</h4>
                        <p class="service-description">Nettoyage et entretien régulier des espaces communs.</p>
                    </div>
                    
                    <!-- Divertissement -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-film"></i>
                        </div>
                        <h4 class="service-title">Salles de Divertissement</h4>
                        <p class="service-description">Cinéma étudiant, jeux vidéo et espaces de détente.</p>
                    </div>
                    
                    <!-- Club des langues -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-language"></i>
                        </div>
                        <h4 class="service-title">Club des Langues Vivantes</h4>
                        <p class="service-description">Pratique des langues étrangères avec locuteurs natifs.</p>
                    </div>
                    
                    <!-- Ateliers -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-hard-hat"></i>
                        </div>
                        <h4 class="service-title">Ateliers d'Apprentissage</h4>
                        <p class="service-description">Espaces pratiques équipés pour la formation technique.</p>
                    </div>
                    
                    <!-- Espaces sportifs -->
                    <div class="service-item">
                        <div class="service-icon">
                            <i class="fas fa-running"></i>
                        </div>
                        <h4 class="service-title">Espaces Sportifs</h4>
                        <p class="service-description">Gymnase, terrains de sport et activités physiques.</p>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-5">
                <button class="btn btn-isgi-primary btn-large" onclick="window.location.href='campus.php'">
                    <i class="fas fa-university"></i> Visiter Notre Campus Virtuel
                </button>
            </div>
        </div>
    </section>

    <!-- Services en Ligne Section -->
    <section class="py-5" style="background-color: #f8f9fa;">
        <div class="container">
            <h2 class="section-title mb-5">Services en Ligne</h2>
            
            <div class="row g-4">
                <!-- Nouvelle Inscription -->
                <div class="col-lg-4">
                    <div class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3 class="h4 mb-3">Nouvelle Inscription</h3>
                        <p class="text-muted mb-4">Devenez étudiant à l'ISGI. Complétez votre inscription en ligne en quelques étapes simples.</p>
                        <button class="btn btn-isgi-primary" onclick="window.location.href='inscription.php'">
                            S'inscrire maintenant
                        </button>
                    </div>
                </div>
                
                <!-- Réinscription -->
                <div class="col-lg-4">
                    <div class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-redo-alt"></i>
                        </div>
                        <h3 class="h4 mb-3">Réinscription</h3>
                        <p class="text-muted mb-4">Étudiants actuels, renouvelez votre inscription pour la nouvelle année académique.</p>
                        <button class="btn btn-isgi-success" onclick="window.location.href='reinscription.php'">
                            Se réinscrire
                        </button>
                    </div>
                </div>
                
                <!-- Espace Étudiant -->
                <div class="col-lg-4">
                    <div class="action-card">
                        <div class="action-icon">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <h3 class="h4 mb-3">Espace Étudiant</h3>
                        <p class="text-muted mb-4">Accédez à vos notes, emploi du temps, paiements et ressources pédagogiques.</p>
                        <button class="btn btn-isgi-secondary" onclick="window.location.href='auth/login.php'">
                            Se connecter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="main-footer">
        <div class="container">
            <div class="row g-4 mb-5">
                <!-- About -->
                <div class="col-lg-4">
                    <div class="footer-section">
                        <h3>ISGI</h3>
                        <p class="mb-3" style="color: rgba(255, 255, 255, 0.8);">Institut Supérieur de Gestion et d'Ingénierie, formant les leaders de demain.</p>
                        <div class="mb-2">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            <span style="color: rgba(255, 255, 255, 0.8);">Brazzaville, Congo</span>
                        </div>
                        <div class="mb-2">
                            <i class="fas fa-phone me-2"></i>
                            <span style="color: rgba(255, 255, 255, 0.8);">+242 06 848 45 67</span>
                        </div>
                        <div class="mb-2">
                            <i class="fas fa-envelope me-2"></i>
                            <span style="color: rgba(255, 255, 255, 0.8);">contact@isgi.cg</span>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Links -->
                <div class="col-lg-4">
                    <div class="footer-section">
                        <h3>Liens rapides</h3>
                        <ul class="footer-links">
                            <li><a href="index.php">Accueil</a></li>
                            <li><a href="inscription.php">Inscription</a></li>
                            <li><a href="reinscription.php">Réinscription</a></li>
                            <li><a href="#bibliotheque">Bibliothèque</a></li>
                            <li><a href="apropos.php">À propos de nous</a></li>
                            <li><a href="contact.php">Nous contacter</a></li>
                        </ul>
                    </div>
                </div>
                
                <!-- Domaines -->
                <div class="col-lg-4">
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
            </div>
            
            <div class="copyright">
                &copy; 2025 ISGI - Institut Supérieur de Gestion et d'Ingénierie. Tous droits réservés.
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Configuration des carrousels circulaires
        const circularCarousels = {
            library: {
                track: document.getElementById('libraryCarouselTrack'),
                indicators: document.getElementById('libraryIndicators'),
                items: [],
                currentIndex: 2,
                totalItems: 5,
                autoRotateInterval: null
            },
            courses: {
                track: document.getElementById('coursesCarouselTrack'),
                indicators: document.getElementById('coursesIndicators'),
                items: [],
                currentIndex: 2,
                totalItems: 6,
                autoRotateInterval: null
            }
        };
        
        // Initialisation des carrousels circulaires
        function initCircularCarousels() {
            for (const carouselName in circularCarousels) {
                const carousel = circularCarousels[carouselName];
                carousel.items = Array.from(carousel.track.querySelectorAll('.circular-carousel-item'));
                createCircularIndicators(carousel);
                updateCircularCarousel(carousel);
                startAutoRotation(carousel);
            }
        }
        
        // Création des indicateurs
        function createCircularIndicators(carousel) {
            carousel.indicators.innerHTML = '';
            for (let i = 0; i < carousel.totalItems; i++) {
                const indicator = document.createElement('div');
                indicator.className = 'circular-carousel-indicator';
                if (i === carousel.currentIndex) indicator.classList.add('active');
                indicator.addEventListener('click', () => {
                    carousel.currentIndex = i;
                    updateCircularCarousel(carousel);
                });
                carousel.indicators.appendChild(indicator);
            }
        }
        
        // Déplacement du carrousel circulaire
        function moveCircularCarousel(carouselName, direction) {
            const carousel = circularCarousels[carouselName];
            carousel.currentIndex += direction;
            
            if (carousel.currentIndex < 0) {
                carousel.currentIndex = carousel.totalItems - 1;
            } else if (carousel.currentIndex >= carousel.totalItems) {
                carousel.currentIndex = 0;
            }
            
            updateCircularCarousel(carousel);
            resetAutoRotation(carousel);
        }
        
        // Mise à jour du carrousel circulaire
        function updateCircularCarousel(carousel) {
            // Mettre à jour les positions des éléments
            carousel.items.forEach((item, index) => {
                // Calculer la position relative
                let position = index - carousel.currentIndex;
                
                // Ajuster pour le défilement circulaire
                if (position < -2) position += carousel.totalItems;
                if (position > 2) position -= carousel.totalItems;
                
                // Appliquer les classes en fonction de la position
                item.className = 'circular-carousel-item';
                
                if (position === 0) {
                    item.classList.add('center');
                } else if (position === -1) {
                    item.classList.add('left');
                } else if (position === 1) {
                    item.classList.add('right');
                } else if (position === -2) {
                    item.classList.add('hidden-left');
                } else if (position === 2) {
                    item.classList.add('hidden-right');
                }
                
                // Mettre à jour l'opacité et le z-index
                item.style.zIndex = 10 - Math.abs(position);
                item.style.opacity = 1 - (Math.abs(position) * 0.2);
            });
            
            // Mettre à jour les indicateurs
            const indicators = carousel.indicators.querySelectorAll('.circular-carousel-indicator');
            indicators.forEach((indicator, index) => {
                indicator.classList.remove('active');
                if (index === carousel.currentIndex) {
                    indicator.classList.add('active');
                }
            });
        }
        
        // Rotation automatique
        function startAutoRotation(carousel) {
            carousel.autoRotateInterval = setInterval(() => {
                moveCircularCarousel(Object.keys(circularCarousels).find(key => circularCarousels[key] === carousel), 1);
            }, 4000);
        }
        
        function resetAutoRotation(carousel) {
            clearInterval(carousel.autoRotateInterval);
            startAutoRotation(carousel);
        }
        
        // Animation simple au scroll
        document.addEventListener('DOMContentLoaded', function() {
            initCircularCarousels();
            
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
            document.querySelectorAll('.stat-card, .library-card, .action-card, .youtube-card, .testimonial-card, .campus-feature, .service-item').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
            
            // Déclencher l'animation après un délai
            setTimeout(() => {
                document.querySelectorAll('.stat-card, .library-card, .action-card, .youtube-card, .testimonial-card, .campus-feature, .service-item').forEach(card => {
                    if (card.getBoundingClientRect().top < window.innerHeight) {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }
                });
            }, 100);
            
            // Ajouter la classe active au lien de navigation actif
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.nav-link').forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                }
            });
            
            // Animation des carrousels au survol
            document.querySelectorAll('.circular-carousel-item').forEach(item => {
                item.addEventListener('mouseenter', function() {
                    if (this.classList.contains('center')) {
                        this.style.transform += ' scale(1.05)';
                    }
                });
                
                item.addEventListener('mouseleave', function() {
                    if (this.classList.contains('center')) {
                        this.style.transform = this.style.transform.replace(' scale(1.05)', '');
                    }
                });
            });
        });
        
        // Pause auto-rotation on hover
        document.querySelectorAll('.circular-carousel-track').forEach(track => {
            track.addEventListener('mouseenter', function() {
                const carouselName = this.id === 'libraryCarouselTrack' ? 'library' : 'courses';
                clearInterval(circularCarousels[carouselName].autoRotateInterval);
            });
            
            track.addEventListener('mouseleave', function() {
                const carouselName = this.id === 'libraryCarouselTrack' ? 'library' : 'courses';
                startAutoRotation(circularCarousels[carouselName]);
            });
        });
    </script>
</body>
</html>