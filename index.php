<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once 'config/database.php';


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="EduPortal LMS for Ruben E. Ecleo Sr. National High School. Manage assignments, grading, and classroom workflows online.">
    <title>EduPortal LMS | Ruben E. Ecleo Sr. National High School</title>
    <link rel="canonical" href="https://reesnhs.l.cd/">
    <link rel="icon" href="assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/pwa-icon-192.svg">
    <style id="critical-css">
        :root {
            color-scheme: dark;
            --primary-color: #4e73df;
            --primary-gradient: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
            --bg-dark: #0a0b10;
            --glass-border: rgba(255, 255, 255, .08);
            --text-main: #f0f2f5;
            --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background: var(--bg-dark);
            color: var(--text-main);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
        }
        a { color: inherit; text-decoration: none; }
        .skip-link {
            position: fixed;
            top: .75rem;
            left: .75rem;
            z-index: 11000;
            padding: .65rem .9rem;
            border-radius: 8px;
            background: var(--primary-color);
            color: #fff;
            transform: translateY(-150%);
        }
        .skip-link:focus { transform: translateY(0); }
        .section-container { width: 100%; padding: 6rem 5%; }
        .premium-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .55rem;
            min-height: 44px;
            padding: .8rem 1.2rem;
            border: 1px solid transparent;
            border-radius: 10px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            transition: transform .2s, box-shadow .2s, background .2s;
        }
        .premium-btn-primary { background: var(--primary-gradient); color: #fff; box-shadow: 0 10px 25px rgba(78, 115, 223, .3); }
        .premium-btn-outline { background: transparent; border-color: var(--glass-border); color: var(--text-main); }
        .premium-btn:hover { transform: translateY(-2px); }
        .premium-badge { display: inline-flex; align-items: center; padding: .4rem .7rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
        .badge-blue { background: rgba(78, 115, 223, .15); color: #91a8ff; border: 1px solid rgba(78, 115, 223, .25); }
        .gradient-text { background: linear-gradient(135deg, #91a8ff, #c084fc); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .blob-container { position: fixed; inset: 0; pointer-events: none; z-index: -1; overflow: hidden; }
        .floating-blob { position: absolute; border-radius: 50%; filter: blur(80px); opacity: .18; }
        .blob-1 { width: 420px; height: 420px; top: -150px; right: -120px; background: #4e73df; }
        .blob-2 { width: 360px; height: 360px; bottom: -180px; left: -120px; background: #a259ff; }
        .nav-desktop { display: flex; align-items: center; }
        .menu-toggle { display: none; align-items: center; justify-content: center; width: 44px; height: 44px; border: 1px solid var(--glass-border); border-radius: 10px; background: transparent; color: var(--text-main); cursor: pointer; }
        .glass-card, .glass-card-premium { border: 1px solid var(--glass-border); background: rgba(20, 22, 30, .7); backdrop-filter: blur(18px); }
        .loader-overlay { position: fixed; inset: 0; z-index: 99999; display: none; align-items: center; justify-content: center; background: rgba(10, 11, 16, .92); }
        .loader-container { color: #fff; text-align: center; }
        @media (max-width: 768px) {
            .nav-desktop { display: none; }
            .menu-toggle { display: inline-flex; }
            .section-container { padding: 4rem 1.25rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; scroll-behavior: auto !important; transition-duration: .01ms !important; }
        }
    </style>
    <link rel="preload" as="style" href="assets/style.min.css?v=20260924" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="assets/style.min.css?v=20260924"></noscript>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <script src="assets/js/trusted_types.js"></script>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "EduPortal",
      "url": "https://reesnhs.l.cd/"
    }
    </script>
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Organization",
      "name": "Ruben E. Ecleo Sr. National High School",
      "alternateName": "reesnhs",
      "url": "https://reesnhs.l.cd/",
      "logo": "https://reesnhs.l.cd/assets/favicon.ico"
    }
    </script>
    <meta property="og:site_name" content="EduPortal" />
</head>

<body style="overflow-x: hidden;">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <!-- Premium Background Decoration (Blobs) -->
    <div class="blob-container">
        <div class="floating-blob blob-1"></div>
        <div class="floating-blob blob-2"></div>
    </div>

    <!-- Navigation -->
    <nav aria-label="Public navigation"
         style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 5%; min-height: var(--header-height); position: relative; z-index: 100; flex-wrap: wrap; gap: 1.5rem;">
        <div class="sidebar-brand" style="font-size: 1.5rem;">
            <i class="fas fa-graduation-cap" style="color: var(--primary-color)"></i> Edu<span>Portal</span>
        </div>
        
        <!-- Mobile Menu Toggle -->
        <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="home-sidebar" aria-expanded="false">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>

        <!-- Desktop Navigation -->
        <div class="nav-desktop" style="gap: 1.5rem; align-items: center;">
            <a href="EDUPORTAL_TEACHER_STUDENT_GUIDE.html" target="_blank" rel="noopener" title="EduPortal Guide"
                style="color: var(--primary-color); text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: all 0.2s;"
                onmouseover="this.style.opacity='0.75'"
                onmouseout="this.style.opacity='1'">Help</a>
            <a href="teacher/login.php"
                style="color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.9rem; transition: color 0.2s;"
                onmouseover="this.style.color='var(--text-main)'"
                onmouseout="this.style.color='var(--text-muted)'">Login as Teacher</a>
            <a href="student/login.php" class="premium-btn premium-btn-primary"
                style="padding: 0.6rem 1.2rem; font-size: 0.9rem;">Login as Student</a>
        </div>
    </nav>

    <!-- Mobile Navigation Sidebar -->
    <aside class="sidebar home-sidebar" id="home-sidebar" aria-label="Public navigation">
        <div class="sidebar-header">
            <div class="sidebar-logo" aria-hidden="true">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="sidebar-brand">
                Edu<span>Portal</span>
            </div>
        </div>
        
        <nav class="sidebar-menu" aria-label="Public mobile navigation">
            <ul>
                <li class="menu-item">
                    <a href="EDUPORTAL_TEACHER_STUDENT_GUIDE.html" target="_blank" rel="noopener" class="menu-link home-menu-link">
                        <div class="home-icon-box" style="color: var(--primary-color); background: rgba(78, 115, 223, 0.1);" aria-hidden="true">
                            <i class="fas fa-circle-question"></i>
                        </div>
                        <div class="home-menu-text">
                            <span class="home-link-title" style="color: var(--primary-color); font-weight: 600;">Help</span>
                            <span class="home-link-subtitle">User Guide & Tutorials</span>
                        </div>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="teacher/login.php" class="menu-link home-menu-link">
                        <div class="home-icon-box" style="color: #a259ff; background: rgba(162, 89, 255, 0.1);" aria-hidden="true">
                            <i class="fas fa-chalkboard-user"></i>
                        </div>
                        <div class="home-menu-text">
                            <span class="home-link-title">Login as Teacher</span>
                            <span class="home-link-subtitle">Teaching & Grading Portal</span>
                        </div>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="student/login.php" class="menu-link home-menu-link">
                        <div class="home-icon-box" style="color: var(--success-color); background: rgba(16, 185, 129, 0.1);" aria-hidden="true">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="home-menu-text">
                            <span class="home-link-title" style="color: var(--primary-color); font-weight: 700;">Login as Student</span>
                            <span class="home-link-subtitle">Learning & Submissions</span>
                        </div>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="sidebar-footer" style="padding: 2rem; border-top: 1px solid var(--glass-border);">
            <p style="font-size: 0.75rem; color: var(--text-muted);">&copy; 2026 EduPortal. All rights reserved.</p>
        </div>
    </aside>

    <main id="main-content">
    <!-- Hero Section -->
    <section class="section-container" style="text-align: center; position: relative; padding-top: 4rem;">
        <div style="max-width: 1000px; margin: 0 auto;">
            <span class="premium-badge badge-blue" style="margin-bottom: 2rem;">Ruben E. Ecleo Sr. National High School Edition</span>
            <h1
                style="font-size: clamp(2.2rem, 8vw, 4.5rem); font-weight: 800; line-height: 1.1; margin-bottom: 1.5rem; letter-spacing: -2px;">
                The Smarter Way to <span class="gradient-text">Manage Learning</span>
            </h1>
            <p
                style="font-size: clamp(1rem, 3vw, 1.3rem); color: var(--text-muted); margin-bottom: 3rem; max-width: 750px; margin-inline: auto;">
                A high-fidelity platform designed for modern educators and learners. Seamlessly track assignments,
                automate grading, and empower your classroom.
            </p>

            <div class="responsive-grid-stack"
                style="display: flex; gap: 1.5rem; justify-content: center; margin-top: 3rem; margin-bottom: 6rem; flex-wrap: wrap;">
                <a href="teacher/signup.php" class="premium-btn premium-btn-outline"
                    style="padding: 1rem 2rem; font-size: 1.1rem; border-color: rgba(255,255,255,0.2); min-width: 240px; justify-content: center;">
                    <i class="fas fa-chalkboard-user"></i> Sign up as Teacher
                </a>
                <a href="student/signup.php" class="premium-btn premium-btn-primary"
                    style="padding: 1rem 2rem; font-size: 1.1rem; border-radius: 16px; min-width: 240px; justify-content: center;">
                    <i class="fas fa-user-plus"></i> Sign up as Student
                </a>
            </div>

            <!-- Dashboard Preview (Modernized) -->
            <div class="glass-card-premium glow-border"
                style="padding: 1rem; max-width: 1050px; margin: 0 auto; overflow: hidden;">
                <div
                    style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.08); padding: 0.8rem 1.5rem; margin-bottom: 1rem;">
                    <div style="display: flex; gap: 8px;">
                        <div
                            style="width: 12px; height: 12px; border-radius: 50%; background: #ff5f57; box-shadow: 0 0 10px rgba(255,95,87,0.4);">
                        </div>
                        <div
                            style="width: 12px; height: 12px; border-radius: 50%; background: #febc2e; box-shadow: 0 0 10px rgba(254,188,46,0.4);">
                        </div>
                        <div
                            style="width: 12px; height: 12px; border-radius: 50%; background: #28c840; box-shadow: 0 0 10px rgba(40,200,64,0.4);">
                        </div>
                    </div>
                    <div
                        style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">
                        <i class="fas fa-shield-halved"
                            style="margin-right: 6px; font-size: 0.75rem; color: var(--primary-color);"></i> Secure
                        Teacher Portal
                    </div>
                </div>

                <div
                    style="position: relative; border-radius: 16px; overflow: hidden; aspect-ratio: 16 / 10; min-height: 300px; max-height: 580px; background: #000; box-shadow: inset 0 0 100px rgba(78, 115, 223, 0.1);">
                    <picture style="display: block; width: 100%; height: 100%;">
                        <source type="image/avif"
                            srcset="assets/dashboard_modern-320.avif 320w, assets/dashboard_modern-640.avif 640w, assets/dashboard_modern-1024.avif 1024w"
                            sizes="(max-width: 1050px) 100vw, 1050px">
                        <source type="image/webp"
                            srcset="assets/dashboard_modern-320.webp 320w, assets/dashboard_modern-640.webp 640w, assets/dashboard_modern-1024.webp 1024w"
                            sizes="(max-width: 1050px) 100vw, 1050px">
                        <img src="assets/dashboard_modern.png?v=1.1" width="1024" height="1024" sizes="(max-width: 1050px) 100vw, 1050px" alt="EduPortal Premium Dashboard"
                            loading="lazy" decoding="async"
                            style="width: 100%; height: 100%; object-fit: cover; opacity: 0.95;">
                    </picture>
                    <div
                        style="position: absolute; inset: 0; background: linear-gradient(to bottom, transparent 60%, rgba(10, 11, 16, 0.8));">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Brands (Social Proof) -->
    <div style="padding: 2rem 5% 6rem; text-align: center; opacity: 0.5;">
        <p
            style="text-transform: uppercase; letter-spacing: 2px; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); margin-bottom: 2rem;">
            Powering Excellence in Schools</p>
        <div
            style="display: flex; justify-content: center; gap: 4rem; align-items: center; flex-wrap: wrap; filter: grayscale(1);">
            <i class="fab fa-google" style="font-size: 2rem;"></i>
            <i class="fab fa-microsoft" style="font-size: 2rem;"></i>
            <i class="fab fa-aws" style="font-size: 2rem;"></i>
            <i class="fab fa-apple" style="font-size: 2rem;"></i>
            <i class="fab fa-slack" style="font-size: 2rem;"></i>
        </div>
    </div>

    <!-- Features Section -->
    <section class="section-container" style="background: rgba(255,255,255,0.02); border-radius: 100px 0 100px 0;">
        <div style="text-align: center; margin-bottom: 5rem;">
            <h2 style="font-size: clamp(2rem, 8vw, 3rem); font-weight: 800; margin-bottom: 1.5rem;">One Platform. <span
                    class="gradient-text">Infinite Potential.</span></h2>
            <p style="color: var(--text-muted); font-size: 1.1rem; max-width: 600px; margin: 0 auto;">Everything you
                need to deliver high-quality education in a digital-first world.</p>
        </div>

        <div class="feature-grid">
            <div class="glass-card" style="padding: 2.5rem;">
                <div
                    style="width: 60px; height: 60px; background: rgba(78, 115, 223, 0.1); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-cloud-arrow-up" style="font-size: 1.5rem; color: var(--primary-color);"></i>
                </div>
                <h3 style="font-size: 1.5rem; margin-bottom: 1rem;">Cloud Submissions</h3>
                <p style="color: var(--text-muted);">Securely upload assignments from any device. Supports PDF, Word,
                    and major file formats up to 10MB.</p>
            </div>
            <div class="glass-card" style="padding: 2.5rem;">
                <div
                    style="width: 60px; height: 60px; background: rgba(16, 185, 129, 0.1); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-square-check" style="font-size: 1.5rem; color: var(--success-color);"></i>
                </div>
                <h3 style="font-size: 1.5rem; margin-bottom: 1rem;">Real-time Grading</h3>
                <p style="color: var(--text-muted);">Teachers can review, grade, and provide detailed feedback
                    instantly. Zero paper, zero delay.</p>
            </div>
            <div class="glass-card" style="padding: 2.5rem;">
                <div
                    style="width: 60px; height: 60px; background: rgba(162, 89, 255, 0.1); border-radius: 16px; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                    <i class="fas fa-chart-pie" style="font-size: 1.5rem; color: #a259ff;"></i>
                </div>
                <h3 style="font-size: 1.5rem; margin-bottom: 1rem;">Analytics Insights</h3>
                <p style="color: var(--text-muted);">Track class performance and individual student growth with
                    beautiful, easy-to-read dashboards.</p>
            </div>
        </div>
    </section>

    <!-- How it Works -->
    <section class="section-container">
        <div style="text-align: center; margin-bottom: 5rem;">
            <h2 style="font-size: clamp(2rem, 8vw, 3rem); font-weight: 800; margin-bottom: 1.5rem;">Simplified <span
                    class="gradient-text">Workflow</span></h2>
            <p style="color: var(--text-muted); font-size: 1.1rem; max-width: 600px; margin: 0 auto;">Transitioning to
                digital management has never been this intuitive.</p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
            <div class="step-card">
                <div class="step-number">1</div>
                <h3 style="font-size: 1.25rem; margin-bottom: 1rem;">Onboard</h3>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Students and teachers create accounts with
                    verified credentials in seconds.</p>
            </div>
            <div class="step-card">
                <div class="step-number">2</div>
                <h3 style="font-size: 1.25rem; margin-bottom: 1rem;">Deploy</h3>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Students upload their assignments directly to
                    their specific subject portals.</p>
            </div>
            <div class="step-card">
                <div class="step-number">3</div>
                <h3 style="font-size: 1.25rem; margin-bottom: 1rem;">Assess</h3>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Teachers view submissions and assign grades
                    with rich feedback tools.</p>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section
        style="padding: 6rem 5%; background: rgba(0,0,0,0.3); border-top: 1px solid var(--glass-border); border-bottom: 1px solid var(--glass-border);">
        <div
            style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-around; flex-wrap: wrap; gap: 3rem;">
            <div style="text-align: center; flex: 1; min-width: 200px;">
                <h3 id="stat-submissions" data-stat="submissions" style="font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; color: var(--primary-color); margin-bottom: 0.5rem;">—</h3>
                <p
                    style="color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem;">
                    Global Submissions</p>
            </div>
            <div style="text-align: center; flex: 1; min-width: 200px;">
                <h3 id="stat-students" data-stat="students" style="font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; color: var(--success-color); margin-bottom: 0.5rem;">—</h3>
                <p
                    style="color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem;">
                    Active Students</p>
            </div>
            <div style="text-align: center; flex: 1; min-width: 200px;">
                <h3 id="stat-teachers" data-stat="teachers" style="font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; color: #a259ff; margin-bottom: 0.5rem;">—</h3>
                <p
                    style="color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem;">
                    Expert Educators</p>
            </div>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="section-container" style="text-align: center;">
        <div class="glass-card"
            style="padding: clamp(2rem, 8vw, 6rem) clamp(1rem, 5vw, 3rem); background: linear-gradient(135deg, rgba(78, 115, 223, 0.1) 0%, rgba(162, 89, 255, 0.1) 100%);">
            <h2 style="font-size: clamp(1.8rem, 6vw, 3.5rem); font-weight: 800; margin-bottom: 1.5rem; letter-spacing: -1.5px; line-height: 1.2;">Ready to
                Start Your <span class="gradient-text">Modern Journey?</span></h2>
            <p
                style="color: var(--text-muted); font-size: clamp(1rem, 2.5vw, 1.25rem); margin-bottom: 3rem; max-width: 650px; margin: 0 auto 3rem;">
                Join thousands of students and teachers already using EduPortal to redefine the digital classroom.</p>
            <div style="display: flex; gap: 1.5rem; justify-content: center;">
                <button onclick="document.getElementById('signupModal').style.display='flex'"
                    class="premium-btn premium-btn-primary" style="padding: 1.2rem 3.5rem; border-radius: 16px;">Create
                    Account</button>
            </div>
        </div>
    </section>
    </main>

    <!-- Sign Up Selection Modal -->
    <div id="signupModal" class="loader-overlay" style="display: none; background: rgba(10, 11, 16, 0.9);">
        <div class="glass-card animate-scale-up"
            style="padding: 3rem; max-width: 500px; width: 90%; text-align: center; border: 1px solid var(--glass-border);">
            <div
                style="display: flex; justify-content: flex-end; margin-top: -1.5rem; margin-right: -1.5rem; margin-bottom: 1rem;">
                <button onclick="document.getElementById('signupModal').style.display='none'"
                    style="background: none; border: none; color: var(--text-muted); font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <h2 style="font-size: 2rem; font-weight: 800; margin-bottom: 1rem;">Get Started</h2>
            <p style="color: var(--text-muted); margin-bottom: 2.5rem;">Choose your account type to begin your journey
                with EduPortal.</p>

            <div style="display: flex; flex-direction: column; gap: 1.2rem;">
                <a href="teacher/signup.php" class="premium-btn premium-btn-outline"
                    style="padding: 1.2rem; justify-content: center;">
                    <i class="fas fa-chalkboard-user"></i> Sign up as Teacher
                </a>
                <a href="student/signup.php" class="premium-btn premium-btn-primary"
                    style="padding: 1.2rem; justify-content: center;">
                    <i class="fas fa-user-graduate"></i> Sign up as Student
                </a>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer style="padding: 3rem 5% 2rem; background: var(--bg-sidebar); border-top: 1px solid var(--glass-border);">
        <div
            style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 3rem;">
            <div>
                <div class="sidebar-brand" style="margin-bottom: 1.2rem; font-size: 1.4rem;">
                    Edu<span>Portal</span>
                </div>
                <p style="color: var(--text-muted); font-size: 0.9rem; line-height: 1.6;">Evolution of academic
                    management with human-centric design at reesnhs.</p>
            </div>
            <div>
                <h2 style="margin-bottom: 1.2rem; font-weight: 700; font-size: 1rem;">Core Framework</h2>
                <ul style="list-style: none; display: flex; flex-direction: column; gap: 0.8rem;">
                    <li style="color: var(--text-muted); font-size: 0.9rem;"><i class="fab fa-html5"
                            style="margin-right: 8px; color: #e34c26;"></i> HTML5 / CSS3</li>
                    <li style="color: var(--text-muted); font-size: 0.9rem;"><i class="fab fa-php"
                            style="margin-right: 8px; color: #777bb4;"></i> PHP 8.1 Engine</li>
                    <li style="color: var(--text-muted); font-size: 0.9rem;"><i class="fas fa-database"
                            style="margin-right: 8px; color: #00758f;"></i> MySQL Relational</li>
                    <li style="color: var(--text-muted); font-size: 0.9rem;"><i class="fab fa-js"
                            style="margin-right: 8px; color: #f7df1e;"></i> Vanilla JS Streams</li>
                </ul>
            </div>
        </div>
        <div
            style="max-width: 1200px; margin: 3rem auto 0; padding-top: 1.5rem; border-top: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
            <p style="color: var(--text-muted); font-size: 0.85rem;">&copy; <?php echo date('Y'); ?> EduPortal.
                Developed by <a href="https://casagan.vercel.app/" target="_blank"
                    style="color: inherit; text-decoration: none; font-weight: 600; transition: color 0.2s;"
                    onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwin T.
                    Casagan</a>.</p>
            <div style="display: flex; gap: 2rem; color: var(--text-muted); font-size: 0.85rem;">
                <span style="opacity: 0.8;"><i class="fas fa-code" style="margin-right: 8px;"></i> Web Developer: <a href="https://casagan.vercel.app/" target="_blank" id="_sys_v_auth" style="color: inherit; text-decoration: none; font-weight: 700; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwin T. Casagan</a></span>
                <span>System Status: <span
                        style="color: var(--success-color); font-weight: 600;">Operational</span></span>
            </div>
        </div>
    </footer>
    <script>
        (() => {
            const loadStats = () => {
                fetch('controllers/public_stats.php', { headers: { Accept: 'application/json' } })
                    .then(response => response.ok ? response.json() : null)
                    .then(data => {
                        if (!data) return;
                        document.querySelectorAll('[data-stat]').forEach(element => {
                            const value = Number(data[element.dataset.stat]);
                            if (Number.isFinite(value)) {
                                element.textContent = value.toLocaleString() + '+';
                            }
                        });
                    })
                    .catch(() => {});
            };
            if ('requestIdleCallback' in window) {
                requestIdleCallback(loadStats, { timeout: 2000 });
            } else {
                window.setTimeout(loadStats, 0);
            }
        })();
    </script>
    <script src="assets/js/system_loader.js?v=20260924-loader4" defer></script>
    <script src="assets/js/responsive_ui.js" defer></script>
    <script src="assets/js/pwa.js" defer></script>
</body>

</html>
