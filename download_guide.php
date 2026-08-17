<?php
// Set headers for Word document download
header('Content-Type: application/vnd.ms-word');
header('Content-Disposition: attachment; filename="Eduportal_Teacher_Student_Guide.doc"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Eduportal - Teacher & Student User Guide</title>
    <style>
        body {
            font-family: 'Calibri', 'Segoe UI', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        h1 {
            font-size: 24pt;
            color: #1a3c6c;
            border-bottom: 3px solid #1a3c6c;
            padding-bottom: 8px;
            margin-top: 0;
        }
        h2 {
            font-size: 16pt;
            color: #1a3c6c;
            border-bottom: 1.5px solid #c0cce0;
            padding-bottom: 4px;
            margin-top: 28px;
            page-break-after: avoid;
        }
        h3 {
            font-size: 13pt;
            color: #2c5282;
            margin-top: 20px;
            page-break-after: avoid;
        }
        h4 {
            font-size: 11pt;
            color: #2d3748;
            margin-top: 14px;
            page-break-after: avoid;
        }
        p {
            margin: 6px 0;
        }
        ol, ul {
            margin: 8px 0;
            padding-left: 30px;
        }
        li {
            margin: 4px 0;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin: 12px 0;
            font-size: 10.5pt;
        }
        th {
            background-color: #1a3c6c;
            color: #fff;
            padding: 8px 12px;
            text-align: left;
            font-weight: bold;
        }
        td {
            border: 1px solid #c0cce0;
            padding: 7px 12px;
            vertical-align: top;
        }
        tr:nth-child(even) {
            background-color: #f0f4f8;
        }
        .note {
            background-color: #fffbea;
            border-left: 4px solid #ecc94b;
            padding: 10px 14px;
            margin: 12px 0;
            font-size: 10.5pt;
        }
        .warning {
            background-color: #fff5f5;
            border-left: 4px solid #fc8181;
            padding: 10px 14px;
            margin: 12px 0;
            font-size: 10.5pt;
        }
        hr {
            border: none;
            border-top: 1px solid #c0cce0;
            margin: 24px 0;
        }
        .footer {
            margin-top: 40px;
            padding-top: 12px;
            border-top: 2px solid #1a3c6c;
            font-size: 9pt;
            color: #888;
            text-align: center;
        }
        @page {
            margin: 1in 1in 1in 1in;
        }
    </style>
</head>
<body>

<h1>Eduportal &mdash; Teacher &amp; Student User Guide</h1>

<hr>

<h2>Getting Started (Both Roles)</h2>
<ol>
    <li>Open your browser and go to the Eduportal homepage (e.g., <code>http://localhost/Eduportal/</code>).</li>
    <li>Click <strong>"Get Started"</strong> or <strong>"Sign Up"</strong> to register, or <strong>"Sign In"</strong> to log in.</li>
</ol>

<hr>

<h2>TEACHER GUIDE</h2>

<h3>Step 1: Create Your Account (Registration)</h3>
<ol>
    <li>Click <strong>"Sign Up as Teacher"</strong> on the landing page.</li>
    <li>Fill in:
        <ul>
            <li><strong>Full Name</strong> &mdash; your professional name</li>
            <li><strong>Professional Email</strong> &mdash; must be a valid email address</li>
            <li><strong>Subject/Specialization</strong> &mdash; the subject you teach (e.g., Mathematics, Physics)</li>
            <li><strong>Password</strong> &mdash; minimum 8 characters, at least 1 uppercase letter and 1 number</li>
            <li><strong>Confirm Password</strong> &mdash; re-enter your password</li>
        </ul>
    </li>
    <li>Click <strong>"Register"</strong>. You'll be redirected to the login page on success.</li>
</ol>

<div class="note">
    <strong>Note:</strong> Teaching multiple subjects? You'll need a separate account for each subject.
</div>

<h3>Step 2: Log In</h3>
<ol>
    <li>Go to the <strong>Teacher Login</strong> page.</li>
    <li>Enter:
        <ul>
            <li><strong>Email</strong> &mdash; the same email you registered with</li>
            <li><strong>Subject</strong> &mdash; the subject you selected during registration</li>
            <li><strong>Password</strong></li>
        </ul>
    </li>
    <li>Click <strong>"Login"</strong>. You'll land on your <strong>Dashboard</strong>.</li>
</ol>

<div class="warning">
    <strong>Security:</strong> After 5 failed login attempts, the system locks you out for 30 seconds. Use the correct email + subject combination.
</div>

<h3>Step 3: Dashboard Overview</h3>
<p>Your dashboard has 4 stat cards at the top:</p>

<table>
    <tr>
        <th>Card</th>
        <th>What It Shows</th>
    </tr>
    <tr>
        <td><strong>Total Submissions</strong></td>
        <td>All assignment files submitted to your subject</td>
    </tr>
    <tr>
        <td><strong>Graded</strong></td>
        <td>Submissions you've already given marks to</td>
    </tr>
    <tr>
        <td><strong>Pending Review</strong></td>
        <td>Submissions waiting for your feedback</td>
    </tr>
    <tr>
        <td><strong>Export Data</strong></td>
        <td>Button to download all submissions as a ZIP</td>
    </tr>
</table>

<p>Below is your <strong>Submission Table</strong> with columns:</p>
<ul>
    <li><strong>#</strong> &mdash; submission number</li>
    <li><strong>Student</strong> &mdash; name, grade level, and section</li>
    <li><strong>File Name</strong> &mdash; download button to view the submitted file</li>
    <li><strong>Date Submitted</strong> &mdash; when the student submitted</li>
    <li><strong>Marks</strong> &mdash; click to enter a grade inline</li>
    <li><strong>Feedback</strong> &mdash; click to enter remarks inline</li>
    <li><strong>Actions</strong> &mdash; checkmark to save, trash icon to delete</li>
</ul>

<p>You can <strong>filter</strong> submissions by Grade Level, Section, and Strand using the filter bar.</p>

<h3>Step 4: Post an Assignment</h3>
<ol>
    <li>In the sidebar, click <strong>"Post Assignment"</strong>.</li>
    <li>Select the <strong>target audience</strong>:
        <ul>
            <li><strong>Grade Level</strong> &mdash; which grade (e.g., Grade 11)</li>
            <li><strong>Strand</strong> &mdash; Academic or Tech-pro</li>
            <li><strong>Section</strong> &mdash; type or select the section (e.g., A, B, ICT)</li>
        </ul>
    </li>
    <li>Fill in:
        <ul>
            <li><strong>Assignment Title</strong></li>
            <li><strong>Instructions/Description</strong> &mdash; detailed directions for students</li>
            <li><strong>File Upload</strong> &mdash; click or drag-and-drop (accepts PDF, DOC, DOCX, TXT, ZIP, JPG, PNG; max 10MB)</li>
        </ul>
    </li>
    <li>Click <strong>"Publish Assignment"</strong>.</li>
    <li>On success, a modal shows how many students were notified via email.</li>
</ol>

<div class="note">
    <strong>Note:</strong> This assignment will <strong>only</strong> appear for students matching the exact Grade + Strand + Section you selected.
</div>

<h3>Step 5: Grade Submissions</h3>
<ol>
    <li>On the <strong>Dashboard</strong>, find the submission in the table.</li>
    <li>Click the <strong>Marks</strong> field and enter the student's grade (e.g., "95" or "A").</li>
    <li>Click the <strong>Feedback</strong> field and type your remarks (e.g., "Great work!").</li>
    <li>Click the <strong>checkmark (save) icon</strong> in the Actions column.</li>
    <li>The submission is saved &mdash; it moves from "Pending" to "Graded."</li>
</ol>

<h3>Step 6: Download All Submissions</h3>
<ol>
    <li>On the Dashboard, click the <strong>"Download ZIP"</strong> button in the Export Data card.</li>
    <li>A ZIP file downloads containing all submitted files, renamed with each student's name for easy identification.</li>
</ol>

<h3>Step 7: View &amp; Manage Students</h3>
<ol>
    <li>In the sidebar, click <strong>[Your Subject] Students</strong>.</li>
    <li>See a card grid of all students who have submitted work to your subject.</li>
    <li>Each card shows: avatar, name, LRN, grade level, strand, section, submission count.</li>
    <li>Use the <strong>search box</strong> to filter by student name or LRN.</li>
    <li>Click <strong>"View History"</strong> to see a student's full submission history on the Dashboard.</li>
    <li>Click the <strong>envelope icon</strong> to send a direct email message to a student.</li>
</ol>

<h3>Step 8: Contact a Student Directly</h3>
<ol>
    <li>From the Students page, click the envelope icon on a student's card.
        <br>Or go directly to <strong>Contact Student</strong> in the sidebar.</li>
    <li>The student's email and your email are pre-filled.</li>
    <li>The subject line is pre-filled with <code>[EduPortal] Academic Concern: {Your Subject}</code>.</li>
    <li>Write your message in the textarea.</li>
    <li>Click <strong>"Send via EduPortal Server"</strong> &mdash; the message is sent through the system's SMTP.</li>
</ol>

<h3>Step 9: Edit Your Profile</h3>
<ol>
    <li>In the sidebar, click <strong>"Profile"</strong>.</li>
    <li>You can update:
        <ul>
            <li><strong>Full Name</strong></li>
            <li><strong>Email Address</strong></li>
            <li><strong>Subject</strong> &mdash; change your teaching subject</li>
        </ul>
    </li>
    <li>To change your password:
        <ul>
            <li>Enter your <strong>current password</strong> for verification</li>
            <li>Enter a <strong>new password</strong> (minimum 6 characters)</li>
            <li>Confirm the new password</li>
        </ul>
    </li>
    <li>Click <strong>"Update Profile"</strong> or <strong>"Change Password"</strong>.</li>
</ol>

<h3>Step 10: Log Out</h3>
<p>Click <strong>"Logout"</strong> in the sidebar. This securely ends your session.</p>

<div class="warning">
    <strong>Forgot your password?</strong> There is no "forgot password" feature yet. Contact your system administrator to reset it.
</div>

<hr>

<h2>STUDENT GUIDE</h2>

<h3>Step 1: Create Your Account (Registration)</h3>
<ol>
    <li>Click <strong>"Sign Up as Student"</strong> on the landing page.</li>
    <li>Fill in:
        <ul>
            <li><strong>LRN</strong> &mdash; your 12-digit Learner Reference Number (exactly 12 digits)</li>
            <li><strong>Full Name</strong></li>
            <li><strong>Grade Level</strong> &mdash; select from Grade 7 to Grade 12 (default: Grade 11)</li>
            <li><strong>Section</strong> &mdash; your class section (e.g., A, B, ICT)</li>
            <li><strong>Strand</strong> &mdash; Academic or Tech-pro (default: Academic)</li>
            <li><strong>Email</strong> &mdash; a valid email address (required for notifications)</li>
            <li><strong>Password</strong> &mdash; minimum 8 characters, 1 uppercase letter, 1 number</li>
            <li><strong>Confirm Password</strong></li>
        </ul>
    </li>
    <li>Click <strong>"Register"</strong>. You'll be redirected to the login page.</li>
</ol>

<h3>Step 2: Log In</h3>
<ol>
    <li>Go to the <strong>Student Login</strong> page.</li>
    <li>Enter your <strong>12-digit LRN</strong> and <strong>Password</strong>.</li>
    <li>Click <strong>"Login"</strong>. You'll land on your <strong>Dashboard</strong>.</li>
</ol>

<h3>Step 3: Dashboard Overview</h3>
<p>Your dashboard has two main areas:</p>

<h4>Top Section &mdash; New Assignments (from Teachers):</h4>
<ul>
    <li>Assignment cards posted by your teachers appear here.</li>
    <li>Each card shows: subject badge, title, description, teacher name, date posted.</li>
    <li>Click <strong>"Get Copy"</strong> to download the assignment file.</li>
</ul>

<h4>Left Panel &mdash; Submit an Assignment:</h4>
<ol>
    <li>Enter the <strong>Subject Name</strong>.</li>
    <li>Click the upload zone or drag-and-drop your file (PDF, DOC, or DOCX only; max 10MB).</li>
    <li>Click <strong>"Submit Now"</strong>.</li>
    <li>On success, you'll see a confirmation message.</li>
</ol>

<h4>Right Panel &mdash; Submission History:</h4>
<ul>
    <li>A table showing all your past submissions with columns:
        <ul>
            <li><strong>Subject</strong> &mdash; color-coded badge</li>
            <li><strong>File</strong> &mdash; download/view button</li>
            <li><strong>Grade</strong> &mdash; your marks (green badge) or "Pending" if not yet graded</li>
            <li><strong>Feedback</strong> &mdash; your teacher's remarks, or "No feedback yet"</li>
            <li><strong>Timestamp</strong> &mdash; date and time of submission</li>
        </ul>
    </li>
</ul>

<h3>Step 4: View New Assignments Page</h3>
<ol>
    <li>In the sidebar, click <strong>"New Assignments"</strong>.</li>
    <li>See all assignments posted by teachers targeting your <strong>Grade + Section + Strand</strong>.</li>
    <li>Each card shows the full details: subject, title, description, teacher name, date.</li>
    <li>Click <strong>"Get Copy"</strong> to download the assignment file to your device.</li>
</ol>

<div class="note">
    <strong>Important:</strong> You only see assignments meant for your specific grade, section, and strand. Other sections or grade levels will have different assignments.
</div>

<h3>Step 5: Submit an Assignment</h3>
<ol>
    <li>On the <strong>Dashboard</strong>, in the Submit Assignment panel:</li>
    <li>Type the <strong>Subject Name</strong> (e.g., "Mathematics").</li>
    <li>Upload your completed assignment file (PDF, DOC, or DOCX, max 10MB).</li>
    <li>Click <strong>"Submit Now"</strong>.</li>
    <li>Your submission appears in the history table with a "Pending" grade.</li>
    <li>When your teacher grades it, the "Pending" badge will change to show your marks and feedback.</li>
</ol>

<h3>Step 6: Check Your Grades &amp; Feedback</h3>
<ol>
    <li>Go to the <strong>Dashboard</strong> &mdash; your Submission History table shows:
        <ul>
            <li><strong>Grade</strong> &mdash; the marks your teacher assigned (green badge when graded)</li>
            <li><strong>Feedback</strong> &mdash; your teacher's written remarks on your work</li>
        </ul>
    </li>
    <li>You'll also receive an <strong>email notification</strong> when new assignments are posted and when your work is graded.</li>
</ol>

<h3>Step 7: Log Out</h3>
<p>Click <strong>"Logout"</strong> in the sidebar.</p>

<hr>

<h2>Quick Reference Summary</h2>

<table>
    <tr>
        <th>Feature</th>
        <th>Teacher</th>
        <th>Student</th>
    </tr>
    <tr>
        <td><strong>Register</strong></td>
        <td>Email + Subject + Password</td>
        <td>12-digit LRN + Grade + Section + Email</td>
    </tr>
    <tr>
        <td><strong>Login</strong></td>
        <td>Email + Subject + Password</td>
        <td>LRN + Password</td>
    </tr>
    <tr>
        <td><strong>Dashboard</strong></td>
        <td>Grade submissions, view stats</td>
        <td>Submit work, view assignments &amp; history</td>
    </tr>
    <tr>
        <td><strong>Post Assignment</strong></td>
        <td>Create &amp; broadcast to specific grade/section/strand</td>
        <td>N/A</td>
    </tr>
    <tr>
        <td><strong>Download Assignments</strong></td>
        <td>Download student submissions as ZIP</td>
        <td>Download teacher-posted files</td>
    </tr>
    <tr>
        <td><strong>Grade</strong></td>
        <td>Enter marks &amp; feedback inline</td>
        <td>View grades &amp; feedback</td>
    </tr>
    <tr>
        <td><strong>Contact</strong></td>
        <td>Send direct emails to students</td>
        <td>N/A</td>
    </tr>
    <tr>
        <td><strong>Profile</strong></td>
        <td>Edit name, email, subject, password</td>
        <td>No profile page (update at registration)</td>
    </tr>
    <tr>
        <td><strong>Email Notifications</strong></td>
        <td>Receives notifications on activity</td>
        <td>Receives new assignment &amp; grading alerts</td>
    </tr>
</table>

<h2>Common Tips</h2>
<ul>
    <li><strong>Password rules:</strong> Minimum 8 characters, at least 1 uppercase letter, at least 1 number.</li>
    <li><strong>Session timeout:</strong> After 30 minutes of inactivity, you'll be redirected to the session expired page &mdash; just log in again.</li>
    <li><strong>Forgot password?</strong> Contact your system administrator &mdash; there's no self-service password reset yet.</li>
    <li><strong>File size limit:</strong> All uploads (assignments and submissions) have a 10MB maximum.</li>
</ul>

<div class="footer">
    Eduportal &mdash; Teacher &amp; Student User Guide<br>
    Generated on August 17, 2026
</div>

</body>
</html>
