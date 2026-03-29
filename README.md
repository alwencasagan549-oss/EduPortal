# EduPortal LMS - Advanced Learning Management System

**Developed by: [Alwen T. Casagan](https://casagan.vercel.app/)** | Web Developer

EduPortal is a premium, production-ready Assignment Portal and Learning Management System (LMS) designed for modern educational institutions. It provides a clean, fast, and secure environment for academic collaboration between students and faculty.

---

## 🚀 Platform Overview

EduPortal leverages a high-performance architecture to deliver a seamless experience across desktop and mobile devices.

### 👨‍🏫 Faculty Control Center
- **Assignment Management**: Effortlessly broadcast materials and instructions to specific grades and sections.
- **Real-Time Grading**: Grade submissions and provide architectural feedback through a centralized dashboard.
- **Data Portability**: Export student submissions into organized ZIP archives for offline review.
- **Automated Hub**: Integrated SMTP notifications to keep students informed about new academic tasks.

### 🎓 Student Learning Hub
- **Submission Engine**: Secure upload portal for PDF and Word documents.
- **Academic Tracking**: View marks and teacher remarks instantly upon grading.
- **Unified Dashboard**: Access all active and completed assignments from one professional interface.
- **Cross-Device Ready**: Fully optimized for mobile, tablet, and desktop accessibility.

---

## 🛠️ Technology Stack (Framework)

The system is built on a high-reliability framework for maximum stability and speed:

- **Frontend**: HTML5, Vanilla CSS3 (Custom Premium Design System)
- **Backend Engine**: PHP 8.1 (Service-Oriented Logic)
- **Database Architecture**: MySQL (Relational Data Management)
- **Interactions**: Vanilla JavaScript (Asynchronous Streams)
- **Security**: Hardened production environment with session isolation.

---

## 📂 Project Structure

```text
Eduportal/
├── admin/               # System Administration Portal
├── student/             # Student Submission & Tracking
├── teacher/             # Faculty Dashboard & Grading
├── config/              # Core Configuration & Credentials
├── controllers/         # Backend Processing & Logic
├── libs/                # System Engines (Queue, Mailer)
├── assets/              # Premium UI Design & Assets
└── uploads/             # Resource & Assignment Storage
```

---

## ⚙️ Installation & Setup

### 1. Requirements
- **PHP**: 8.1 or higher
- **Database**: MySQL 5.7+
- **Server**: Apache / Nginx (XAMPP/Laragon recommended for local)

### 2. Database Setup
1. Create a MySQL database (e.g., `edu_portal`).
2. Import the database schema from `data/eduportal_final.sql`.

### 3. Application Configuration
1. Update your database and SMTP credentials in `config/credentials.php`.
2. Ensure the `uploads/` directory has write permissions on your server.

---

## 📜 Intellectual Property & Disclaimer

This software is developed and maintained by **Alwen T. Casagan**. It is intended for educational purposes and institutional management.

© 2026 EduPortal LMS. All rights reserved. Developed by [Alwen T. Casagan](https://casagan.vercel.app/).