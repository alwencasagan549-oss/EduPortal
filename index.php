<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once 'config/database.php';

// Get some quick stats for the landing page
$conn = getDBConnection();
$res1 = $conn->query("SELECT COUNT(*) as count FROM submissions");
$total_submissions = $res1->fetch_assoc()['count'] ?? 0;
$res2 = $conn->query("SELECT COUNT(*) as count FROM teachers");
$total_teachers = $res2->fetch_assoc()['count'] ?? 0;
$res3 = $conn->query("SELECT COUNT(*) as count FROM students");
$total_students = $res3->fetch_assoc()['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduPortal LMS | Modern Learning Management</title>
    <link rel="icon" href="assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body style="overflow-x: hidden;">
    <!-- Premium Background Decoration (Blobs) -->
    <div class="blob-container">
        <div class="floating-blob blob-1"></div>
        <div class="floating-blob blob-2"></div>
    </div>

    <!-- Navigation -->
    <nav
        style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 5%; min-height: var(--header-height); position: relative; z-index: 100; flex-wrap: wrap; gap: 1.5rem;">
        <div class="sidebar-brand" style="font-size: 1.5rem;">
            <i class="fas fa-graduation-cap" style="color: var(--primary-color)"></i> Edu<span>Portal</span>
        </div>
        
        <!-- Mobile Menu Toggle -->
        <button class="menu-toggle">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Desktop Navigation -->
        <div class="nav-desktop" style="gap: 1.5rem; align-items: center;">
            <a href="admin/login.php"
                style="color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.9rem; transition: color 0.2s;"
                onmouseover="this.style.color='var(--text-main)'"
                onmouseout="this.style.color='var(--text-muted)'">Login as Admin</a>
            <a href="teacher/login.php"
                style="color: var(--text-muted); text-decoration: none; font-weight: 500; font-size: 0.9rem; transition: color 0.2s;"
                onmouseover="this.style.color='var(--text-main)'"
                onmouseout="this.style.color='var(--text-muted)'">Login as Teacher</a>
            <a href="student/login.php" class="premium-btn premium-btn-primary"
                style="padding: 0.6rem 1.2rem; font-size: 0.9rem;">Login as Student</a>
        </div>
    </nav>

    <!-- Mobile Navigation Sidebar -->
    <aside class="sidebar home-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="sidebar-brand">
                Edu<span>Portal</span>
            </div>
        </div>
        
        <nav class="sidebar-menu">
            <li class="menu-item">
                <a href="admin/login.php" class="menu-link home-menu-link">
                    <div class="home-icon-box" style="color: var(--primary-color); background: rgba(78, 115, 223, 0.1);">
                        <i class="fas fa-shield-halved"></i> 
                    </div>
                    <div class="home-menu-text">
                        <span class="home-link-title">Login as Admin</span>
                        <span class="home-link-subtitle">Management & Controls</span>
                    </div>
                </a>
            </li>
            <li class="menu-item">
                <a href="teacher/login.php" class="menu-link home-menu-link">
                    <div class="home-icon-box" style="color: #a259ff; background: rgba(162, 89, 255, 0.1);">
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
                    <div class="home-icon-box" style="color: var(--success-color); background: rgba(16, 185, 129, 0.1);">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="home-menu-text">
                        <span class="home-link-title" style="color: var(--primary-color); font-weight: 700;">Login as Student</span>
                        <span class="home-link-subtitle">Learning & Submissions</span>
                    </div>
                </a>
            </li>
        </nav>

        <div class="sidebar-footer" style="padding: 2rem; border-top: 1px solid var(--glass-border);">
            <p style="font-size: 0.75rem; color: var(--text-muted);">&copy; 2026 EduPortal. All rights reserved.</p>
        </div>
    </aside>

    <!-- Hero Section -->
    <section class="section-container" style="text-align: center; position: relative; padding-top: 4rem;">
        <div style="max-width: 1000px; margin: 0 auto;" class="animate-fade-up">
            <span class="premium-badge badge-blue" style="margin-bottom: 2rem;">2024 Academic Edition</span>
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
            <div class="glass-card-premium animate-float glow-border"
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
                    style="position: relative; border-radius: 16px; overflow: hidden; min-height: 300px; max-height: 580px; background: #000; box-shadow: inset 0 0 100px rgba(78, 115, 223, 0.1);">
                    <img src="assets/dashboard_modern.png?v=1.1" alt="EduPortal Premium Dashboard"
                        style="width: 100%; height: 100%; object-fit: cover; opacity: 0.95;">
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
                <h4 style="font-size: 1.25rem; margin-bottom: 1rem;">Onboard</h4>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Students and teachers create accounts with
                    verified credentials in seconds.</p>
            </div>
            <div class="step-card">
                <div class="step-number">2</div>
                <h4 style="font-size: 1.25rem; margin-bottom: 1rem;">Deploy</h4>
                <p style="color: var(--text-muted); font-size: 0.95rem;">Students upload their assignments directly to
                    their specific subject portals.</p>
            </div>
            <div class="step-card">
                <div class="step-number">3</div>
                <h4 style="font-size: 1.25rem; margin-bottom: 1rem;">Assess</h4>
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
                <h3 style="font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; color: var(--primary-color); margin-bottom: 0.5rem;">
                    <?php echo number_format($total_submissions); ?>+
                </h3>
                <p
                    style="color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem;">
                    Global Submissions</p>
            </div>
            <div style="text-align: center; flex: 1; min-width: 200px;">
                <h3 style="font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; color: var(--success-color); margin-bottom: 0.5rem;">
                    <?php echo number_format($total_students); ?>+
                </h3>
                <p
                    style="color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem;">
                    Active Students</p>
            </div>
            <div style="text-align: center; flex: 1; min-width: 200px;">
                <h3 style="font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; color: #a259ff; margin-bottom: 0.5rem;">
                    <?php echo number_format($total_teachers); ?>+
                </h3>
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
                    management with human-centric design.</p>
                <div style="display: flex; gap: 1.2rem; margin-top: 1.5rem;">
                    <a href="#" style="color: var(--text-muted); font-size: 1.1rem;"><i class="fab fa-twitter"></i></a>
                    <a href="#" style="color: var(--text-muted); font-size: 1.1rem;"><i class="fab fa-facebook"></i></a>
                    <a href="#" style="color: var(--text-muted); font-size: 1.1rem;"><i class="fab fa-linkedin"></i></a>
                    <a href="#" style="color: var(--text-muted); font-size: 1.1rem;"><i
                            class="fab fa-instagram"></i></a>
                </div>
            </div>
            <div>
                <h4 style="margin-bottom: 1.2rem; font-weight: 700; font-size: 1rem;">Core Framework</h4>
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
                    onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T.
                    Casagan</a>.</p>
            <div style="display: flex; gap: 2rem; color: var(--text-muted); font-size: 0.85rem;">
                <span style="opacity: 0.8;"><i class="fas fa-code" style="margin-right: 8px;"></i> Web Developer: <a href="https://casagan.vercel.app/" target="_blank" id="_sys_v_auth" style="color: inherit; text-decoration: none; font-weight: 700; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></span>
                <span>System Status: <span
                        style="color: var(--success-color); font-weight: 600;">Operational</span></span>
            </div>
        </div>
    </footer>
    <script src="assets/js/system_loader.js"></script>
    <script src="assets/js/responsive_ui.js"></script>
</body>

</html>