# EduPortal LMS - User Guide

## Table of Contents

1. [What is EduPortal?](#what-is-eduportal)
2. [System Requirements](#system-requirements)
3. [Getting Started](#getting-started)
4. [Teacher Guide](#teacher-guide)
5. [Student Guide](#student-guide)
6. [Admin Guide](#admin-guide)
7. [How the System Works](#how-the-system-works)
8. [Communication System](#communication-system)
9. [Security Features](#security-features)
10. [FAQ & Troubleshooting](#faq--troubleshooting)

---

## What is EduPortal?

EduPortal is a **Learning Management System (LMS)** designed for schools to manage assignments, submissions, and grading between teachers and students. It replaces the traditional paper-based assignment collection with a digital system where:

- **Teachers** post assignments, collect student submissions, grade them, and give feedback
- **Students** view assignments posted specifically for their grade/section, submit their work, and see their grades
- **Admins** oversee all submissions across the entire school

---

## System Requirements

- A modern web browser (Chrome, Firefox, Edge, Safari)
- Internet connection
- For file uploads: PDF or DOCX format, maximum 10MB per file

---

## Getting Started

### Step 1: Access the Portal

Open your web browser and go to:
```
https://eduportal-xefr.onrender.com
```

You will see the EduPortal landing page with options to **Sign In** or **Sign Up**.

### Step 2: Create an Account

Choose your role and click **Sign Up**:

#### For Teachers:
- Fill in your **Full Name**, **Professional Email**, **Subject/Specialization** (e.g., Mathematics, Physics, English), and **Password**
- Click **Sign Up**
- You will be redirected to the login page

#### For Students:
- Fill in your **LRN (Learner Reference Number)** - exactly 12 digits, your **Full Name**, **Grade Level**, **Section**, **Email** (optional), and **Password**
- Click **Sign Up**
- You will be redirected to the login page

### Step 3: Log In

Use your credentials to log in:

| Role | Username/Email | Password |
|------|---------------|----------|
| Teacher | your email + subject | your password |
| Student | your 12-digit LRN | your password |
| Admin | `admin` | `admin123` |

---

## Teacher Guide

### Dashboard Overview

After logging in, you will see your **Teacher Dashboard** with four main stat cards:

| Card | Description |
|------|-------------|
| **Total Submissions** | Number of assignment files students have submitted to you |
| **Graded** | Submissions you have already marked with grades and feedback |
| **Pending Review** | Submissions waiting for your grade and feedback |
| **Export Data** | Button to download all submissions as a ZIP file |

Below the stats is your **Submission Table** showing all student submissions for your subject.

### Feature 1: Post an Assignment

Use this to create and broadcast an assignment to specific students.

1. Click **Post Assignment** in the sidebar
2. Select the **Grade Level** (e.g., Grade 11)
3. Select the **Section** (e.g., STEM-A) - only students in this grade AND section will receive this assignment
4. Enter the **Assignment Title** and **Instructions/Description**
5. Upload the assignment file (PDF or DOCX, max 10MB)
6. Click **Post Assignment**

**What happens next:**
- The assignment file is saved and stored in the system
- All students matching the selected grade and section will see this assignment on their "New Assignments" page
- An email notification is automatically sent to each affected student

### Feature 2: View and Grade Submissions

1. Go to your **Dashboard**
2. Use the **Filter Bar** at the top to filter by Grade Level and/or Section
3. Click **Apply** to filter the list
4. In the submission table, each row shows:
   - **#** - Submission ID
   - **Student** - Student name and their grade/section
   - **File Name** - Download button to view the submitted file
   - **Date Submitted** - When the student submitted
   - **Marks** - Enter the grade/score here
   - **Feedback** - Enter comments or remarks for the student
   - **Actions** - Click the checkmark (save) or trash (delete)

5. Enter marks and feedback, then click the **checkmark** to save
6. The student will be able to see your grade and feedback on their dashboard

### Feature 3: Download All Submissions

1. Click the **Download ZIP** button on your dashboard
2. A ZIP file containing all submissions for your subject will be downloaded
3. Each file is named with the student's name for easy identification

### Feature 4: Delete a Submission

1. Find the submission in your dashboard table
2. Click the **trash icon** button
3. Confirm the deletion
4. The submission record and file will be permanently removed

### Feature 5: View Student Directory

1. Click **Students** in the sidebar
2. See all students who have submitted work to your subject displayed as cards
3. Each card shows: name, LRN, grade, section, submission count, latest submission date, and average performance
4. Use the **search box** to find a specific student by name or LRN
5. Click **Contact** to send a direct message to a student

### Feature 6: Contact a Student

1. From the Students page, click the **envelope icon** on a student's card
2. Compose your message in the form
3. The subject line is pre-filled as `[EduPortal] Academic Concern: {your subject}`
4. Write your message in the body
5. Click **Send Message**
6. The student will receive your message via email

### Feature 7: Edit Your Profile

1. Click **Profile** in the sidebar
2. View your current information
3. To make changes:
   - Enter your **current password** (required)
   - Update your name, email, or subject
   - Enter a **new password** (minimum 6 characters) if you want to change it
4. Click **Update Profile**

---

## Student Guide

### Dashboard Overview

After logging in, you will see your **Student Dashboard** with two main sections:

**Left Panel - Submit Assignment:**
- Subject name input
- File upload area (drag & drop or click to browse)

**Right Panel - Submission History:**
- Table showing all your past submissions with grades and feedback

### Feature 1: Submit an Assignment

1. Go to your **Dashboard**
2. In the **Submit Assignment** section on the left:
   - Enter the **Subject Name** (e.g., Mathematics)
   - Click the upload area or drag your file to upload
   - Accepted formats: **PDF** or **DOCX** only
   - Maximum file size: **10MB**
3. Click **Submit Assignment**
4. You will see a success message if the submission was accepted

### Feature 2: View Submitted Assignments

In the **Submission History** table on your dashboard:

| Column | Description |
|--------|-------------|
| **Subject** | The subject name you entered when submitting |
| **File** | Click to download or view your submitted file |
| **Grade** | Shows your marks (green badge) or "Pending" if not yet graded |
| **Feedback** | Shows teacher comments or "No feedback yet" |
| **Time** | Date and time you submitted |

### Feature 3: View New Assignments from Teachers

1. Click **New Assignments** in the sidebar (or navigate to the assignments page)
2. You will see **assignment cards** posted by your teachers specifically for your grade and section
3. Each card shows:
   - Subject badge
   - Assignment title and description
   - Teacher name
   - Date posted
4. Click **Get Copy** to download the assignment file

### Feature 4: Edit Your Profile

1. Click your name or profile icon in the sidebar
2. Update your name, grade, section, or password
3. Click **Save Changes**

---

## Admin Guide

### Dashboard Overview

After logging in as admin, you will see:

| Stat Card | Description |
|-----------|-------------|
| **Total Submissions** | All assignment submissions across all subjects |
| **Graded** | Total graded submissions school-wide |
| **Pending Review** | Submissions not yet graded |
| **Mass Export** | Export all submissions |

### Feature 1: View All Submissions

The admin dashboard shows **every submission** from every student in every subject. This gives you full oversight of the entire school's assignment activity.

### Feature 2: Grade Any Submission

1. Find any submission in the table
2. Enter **Marks** and **Feedback**
3. Click the **checkmark** to save
4. The student and teacher will see your grade

### Feature 3: Delete Any Submission

1. Find the submission in the table
2. Click the **delete** button
3. Confirm the deletion
4. Both the database record and the physical file are permanently removed

---

## How the System Works

### The Assignment Lifecycle

```
TEACHER                          STUDENT
   |                                |
   |  1. Posts Assignment           |
   |  (selects grade + section)     |
   |                                |
   |  2. File saved to server       |
   |  3. Record created             |
   |                                |
   |  4. Email notification sent    |
   |  --------------------------->  |
   |                                |
   |                    5. Student sees assignment
   |                       on "New Assignments" page
   |                                |
   |                    6. Student downloads file
   |                       and completes work
   |                                |
   |                    7. Student submits file
   |                       via dashboard
   |                                |
   |  8. File saved to server       |
   |  9. Record created             |
   |                                |
   | 10. Teacher sees submission    |
   |     on dashboard               |
   |                                |
   | 11. Teacher grades + feedback  |
   |                                |
   | 12. Student sees grade         |
   |     on dashboard               |
```

### Database Structure

The system uses **6 main database tables**:

| Table | Purpose |
|-------|---------|
| `admin` | Administrator accounts |
| `teachers` | Teacher accounts (identified by email + subject) |
| `students` | Student accounts (identified by 12-digit LRN) |
| `submissions` | Student assignment submissions |
| `posted_assignments` | Teacher-posted assignments for specific grades/sections |
| `jobs` | Background email queue |

### Selective Assignment Delivery

Assignments are **not** sent to everyone. They are targeted:

- A teacher selects a **Grade Level** (e.g., Grade 11) and **Section** (e.g., STEM-A)
- Only students with `grade_level = 'Grade 11' AND section = 'STEM-A'` see that assignment
- This allows teachers to give different assignments to different class sections

### File Storage

- All uploaded files are stored on the server in the `uploads/` folder
- Student submissions go to `uploads/` directly
- Teacher assignment files go to `uploads/assignments/`
- Files are renamed with a timestamp prefix to prevent overwriting

---

## Communication System

### How Teachers and Students Communicate

EduPortal has **two communication channels**:

### 1. Assignment-Based Communication (Automatic)

When a teacher posts an assignment:
- The system automatically sends an **email notification** to every student in the target grade/section
- The email tells the student: *"Your teacher [Name] has posted a new assignment for [Subject]"*
- This is handled automatically - the teacher does not need to manually notify each student

### 2. Direct Messages (Manual)

Teachers can send direct messages to individual students:

1. Go to the **Students** page
2. Find the student and click the **envelope icon**
3. Write your message
4. Click **Send Message**
5. The student receives your message via email

### How Email Works Behind the Scenes

The system uses a **background job queue** for sending emails:

1. When an email needs to be sent, it is added to a **jobs table** in the database
2. The job sits in the queue with status `pending`
3. A background processor picks up the job and sends the email via SMTP
4. The job status updates to `completed` or `failed`

This means emails are sent in the background without slowing down the website. Even if you close the page, the email will still be sent.

### Email Requirements

For emails to work, the server needs valid Gmail SMTP credentials configured. If SMTP is not set up:
- Assignments will still be posted normally
- Students will still see assignments on their dashboard
- Email notifications may not be delivered, but the core functionality is not affected

---

## Security Features

EduPortal includes multiple security protections:

| Feature | What It Does |
|---------|-------------|
| **Password Hashing** | All passwords are encrypted using bcrypt - never stored as plain text |
| **Login Throttling** | 5 failed login attempts triggers a 30-second lockout to prevent brute-force attacks |
| **Session Protection** | Sessions are regenerated on login, cookies are HttpOnly and SameSite=Strict |
| **File Validation** | Only PDF and DOCX files are accepted; files are scanned and renamed to prevent attacks |
| **Access Control** | Teachers can only access their own subject's data; students can only access their own submissions |
| **Input Sanitization** | All user input is cleaned to prevent injection attacks |

---

## FAQ & Troubleshooting

### General Questions

**Q: I forgot my password. What do I do?**
A: Contact your system administrator to reset your password. There is no "forgot password" feature yet.

**Q: Can I change my grade level or section after signing up?**
A: Yes. Go to your Profile page and update your information. Teachers can update their subject. Students can update their grade and section.

**Q: What file types can I upload?**
A: Only **PDF** and **DOCX** files are accepted. Maximum file size is **10MB**.

**Q: Can I delete a submission after submitting?**
A: Teachers can delete submissions. Students cannot delete their own submissions - contact your teacher if you submitted the wrong file.

**Q: Will I get notified when my assignment is graded?**
A: Currently, you can check your dashboard to see grades and feedback. Email notification for grading is not yet implemented.

**Q: Can a teacher teach multiple subjects?**
A: Currently, each teacher account is tied to one subject. To teach multiple subjects, you would need separate accounts.

### Technical Issues

**Q: The page shows "Database connection error"**
A: This usually means the server is temporarily unavailable. Wait a few moments and refresh the page.

**Q: My file upload is failing**
A: Check that:
- The file is PDF or DOCX format
- The file is under 10MB
- Your internet connection is stable

**Q: I don't see the assignment my teacher posted**
A: Make sure you are logged in with the correct grade level and section. Assignments are targeted to specific grade/section combinations.

**Q: The "New Assignments" page is empty**
A: Your teacher may not have posted any assignments targeting your specific grade and section yet, or they may have posted assignments for a different section.

---

## Quick Reference

### Teacher Workflow
1. Login with email + subject + password
2. Post Assignment (select grade/section, upload file)
3. Monitor Dashboard for new submissions
4. Grade submissions (marks + feedback)
5. Download all submissions as ZIP
6. Contact students via direct message if needed

### Student Workflow
1. Login with LRN + password
2. Check "New Assignments" for teacher-posted work
3. Download assignment files
4. Complete the work
5. Submit via Dashboard (upload PDF/DOCX)
6. Check Dashboard for grades and feedback

### Default Login Credentials (for testing)

| Role | Username/Email | Password |
|------|---------------|----------|
| Admin | `admin` | `admin123` |
| Teacher | `john@example.com` | `teacher123` |
| Teacher | `sarah@example.com` | `teacher123` |
| Student | LRN: `123456789012` | `student123` |

---

*EduPortal LMS - Built by Alwen T. Casagan*
