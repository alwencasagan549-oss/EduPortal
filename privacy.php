<?php
/**
 * Privacy Policy.
 *
 * Written from what this codebase actually does rather than from a generic
 * template, because a notice that over-promises is the kind of defect nobody
 * finds until a parent asks a question the page cannot answer. The table in
 * "What we collect" maps to the real tables in data/eduportal_final.sql and the
 * real third parties in .env.example: Google (reCAPTCHA v3, Analytics 4), the
 * configured S3-compatible object store, and the configured SMTP relay.
 */

require_once __DIR__ . '/libs/legal_page.php';

$toc = [
    'who-we-are' => 'Who we are',
    'what-we-collect' => 'What we collect',
    'why-we-collect-it' => 'Why we collect it',
    'cookies-and-analytics' => 'Cookies and analytics',
    'who-we-share-it-with' => 'Who we share it with',
    'how-long-we-keep-it' => 'How long we keep it',
    'how-we-protect-it' => 'How we protect it',
    'your-rights' => 'Your rights',
    'children' => 'Children and students',
    'changes' => 'Changes to this notice',
    'how-to-contact-us' => 'How to contact us',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php render_legal_head(
        'Privacy Policy',
        'How EduPortal collects, uses, shares and protects personal information, and how to exercise your rights under Republic Act No. 10173.'
    ); ?>
</head>

<body>
    <a class="skip-link" href="#main-content">Skip to the policy</a>
    <main class="legal-shell" id="main-content">
        <?php render_legal_topbar(); ?>
        <?php render_legal_masthead(
            'fa-shield-halved',
            'Legal notice',
            'Privacy Policy',
            'This notice explains what personal information ' . legal_entity_name() . ' collects when you use '
            . 'EduPortal, why it is collected, who it is shared with, and how you can get a copy of it, correct it, '
            . 'or ask for it to be deleted.'
        ); ?>

        <?php render_legal_unconfigured_notice(); ?>

        <div class="legal-layout">
            <?php render_legal_toc($toc); ?>

            <div class="legal-body">
                <section id="who-we-are">
                    <h2>1. Who we are</h2>
                    <p>
                        <strong><?php echo legal_e(legal_entity_name()); ?></strong> ("the School", "we") operates
                        EduPortal, the assignment and learning management portal published at
                        <a href="index.php">this site</a>. For the purposes of Republic Act No. 10173 of the
                        Philippines, the Data Privacy Act of 2012, the School is the <strong>personal information
                        controller</strong> of the information described below. The School is responsible for the
                        lawful processing of that information and for the accuracy of the records it holds.
                    </p>
                    <p>
                        The portal is operated for public educational purposes. The School processes information
                        under the following bases recognised by Republic Act No. 10173, Section 13:
                    </p>
                    <ul>
                        <li><strong>Contractual necessity</strong> — an account, a submission and a grade cannot
                            exist without the information that identifies the person they belong to.</li>
                        <li><strong>Legal obligation</strong> — keeping academic and enrolment records as a public
                            secondary school is required by law.</li>
                        <li><strong>Protection of life and property</strong> — account security controls exist to
                            stop one student's account being used against another.</li>
                        <li><strong>Consent</strong> — where a specific, optional use is not covered by the bases
                            above, and for processing that touches a person who is still a minor.</li>
                    </ul>
                </section>

                <section id="what-we-collect">
                    <h2>2. What we collect</h2>
                    <p>
                        We do not collect information we do not need for the portal to work. The categories below
                        are the complete set.
                    </p>
                    <div class="legal-table-wrap">
                        <table class="legal-table">
                            <thead>
                                <tr>
                                    <th scope="col">Category</th>
                                    <th scope="col">Examples</th>
                                    <th scope="col">Why it is needed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Account and enrolment</td>
                                    <td>Learner Reference Number, name, email address, grade level, section,
                                        strand; for teachers, name, email address and teaching subject</td>
                                    <td>To identify your account, place you in the right class and section, and
                                        route work to the right teacher</td>
                                </tr>
                                <tr>
                                    <td>Credentials</td>
                                    <td>A one-way hash of your password; a recovery token, stored only as a
                                        SHA-256 digest; public keys and credential IDs for passkeys</td>
                                    <td>To let you sign in and, if you lose your password, to prove that a
                                        recovery request is really yours</td>
                                </tr>
                                <tr>
                                    <td>Academic work</td>
                                    <td>Uploaded assignment files, submission dates, marks, and remarks left by a
                                        teacher</td>
                                    <td>To deliver work to the teacher who set it and to return results to you</td>
                                </tr>
                                <tr>
                                    <td>Messages</td>
                                    <td>Notifications you receive, and messages a teacher sends you from the
                                        portal</td>
                                    <td>To tell you about new assignments, grades and feedback</td>
                                </tr>
                                <tr>
                                    <td>Security and audit</td>
                                    <td>IP address, browser and device information, timestamps of sign-in attempts,
                                        password changes and passkey enrolment; failed-attempt counters</td>
                                    <td>To detect and limit credential guessing and account takeover</td>
                                </tr>
                                <tr>
                                    <td>Site usage</td>
                                    <td>Pages viewed, the page a visit came from, and the IP-derived city and
                                        device type recorded by Google Analytics</td>
                                    <td>To understand which parts of the portal students actually use</td>
                                </tr>
                                <tr>
                                    <td>Contact form</td>
                                    <td>Name, email address, chosen topic and message, if you send us one</td>
                                    <td>To answer you</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p>
                        <strong>What we do not collect.</strong> The School does not collect sensitive personal
                        information as defined by law, such as health, biometric, genetic, or religious data, and
                        EduPortal has no field that would accept it. Passkeys are a public-key credential handled
                        by your device: the portal stores a public key and a usage counter, never your fingerprint,
                        face geometry, or any other biometric template.
                    </p>
                </section>

                <section id="why-we-collect-it">
                    <h2>3. Why we collect it</h2>
                    <p>
                        Every category above is used to operate the portal, to meet the School's obligations as a
                        public secondary school, or to protect accounts. We do not use student information for
                        advertising, and we do not build profiles for marketing purposes. We do not sell, rent or
                        trade personal information to anyone.
                    </p>
                    <p>
                        Grade and section are used to target an assignment at the right class. They are not used to
                        rank, compare or report on individual students outside the classroom.
                    </p>
                </section>

                <section id="cookies-and-analytics">
                    <h2>4. Cookies and analytics</h2>
                    <p>EduPortal uses three kinds of browser storage, each serving a different purpose:</p>
                    <div class="legal-table-wrap">
                        <table class="legal-table">
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Purpose</th>
                                    <th scope="col">Who sets it</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>PHP session cookie</td>
                                    <td>Keeps you signed in and carries the CSRF token that protects forms from
                                        cross-site request forgery</td>
                                    <td>EduPortal</td>
                                </tr>
                                <tr>
                                    <td>Human-verification cookie</td>
                                    <td>Remembers that this browser has already passed the first-visit check, so a
                                        verified visitor is not challenged on every page</td>
                                    <td>EduPortal</td>
                                </tr>
                                <tr>
                                    <td>Analytics cookies</td>
                                    <td>Distinguishes visitors and sessions for Google Analytics 4 reporting</td>
                                    <td>Google</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p>
                        Google Analytics 4 is configured deliberately narrowly. The portal sends
                        <strong>no user identifier and no custom dimensions</strong>: your Learner Reference Number
                        and your email address are never sent to Google, because that system does not share this
                        portal's access controls, retention period or deletion path. Advertising and
                        personalisation cookies are not enabled. Role-level reporting is still available without
                        them.
                    </p>
                    <p>
                        Sessions end after 30 minutes of inactivity and in all cases after 12 hours. Signing out
                        destroys the session immediately.
                    </p>
                </section>

                <section id="who-we-share-it-with">
                    <h2>5. Who we share it with</h2>
                    <p>
                        The School shares personal information only with the following, and only to the extent
                        needed to operate the portal. Each of these is a processor acting on the School's
                        instructions.
                    </p>
                    <ul>
                        <li><strong>Google LLC</strong> — for reCAPTCHA v3, which scores sign-up, sign-in and
                            password-reset requests to stop automated abuse, and for the first-visit human check.
                            Google's reCAPTCHA terms apply to those requests.</li>
                        <li><strong>Google LLC</strong> — for Google Analytics 4, which records anonymous site
                            usage as described in Section 4.</li>
                        <li><strong>Cloudflare R2, or another S3-compatible object store</strong> — as configured
                            by the School — which stores the files students upload when large-file upload is
                            enabled. Files are stored under opaque keys; the object store is not given a list of
                            student names.</li>
                        <li><strong>The School's email relay</strong> — as configured by the School — to deliver
                            password-reset messages only.</li>
                        <li><strong>Law enforcement or a court</strong>, where the School is legally compelled to
                            disclose information, and only for the records the order covers.</li>
                    </ul>
                    <p>
                        We do not sell, rent or trade personal information to anyone. We do not share student
                        records with other schools, advertisers, or data brokers.
                    </p>
                </section>

                <section id="how-long-we-keep-it">
                    <h2>6. How long we keep it</h2>
                    <ul>
                        <li><strong>Account and enrolment records</strong> — for as long as the account is open,
                            and afterwards for the period the School's student records retention schedule
                            requires, because academic records are subject to legal retention rules that outlast
                            an account.</li>
                        <li><strong>Assignment files, marks and remarks</strong> — for the life of the assignment
                            and the academic year it belongs to, then archived or deleted in line with the School's
                            records schedule. When a submission is removed, the deletion is recorded in an audit
                            log that keeps the fact of the deletion but not the file.</li>
                        <li><strong>Security and audit events</strong> — for a limited period after the event, on
                            the order of months, unless needed to investigate an ongoing incident.</li>
                        <li><strong>Analytics data</strong> — for the retention period Google applies to
                            Analytics 4 properties, after which it is aggregated or deleted.</li>
                        <li><strong>Contact form messages</strong> — until the matter is resolved and the record is
                            no longer needed for reference.</li>
                    </ul>
                    <p>
                        When information is deleted, it is removed from the live database and, where the portal
                        holds a copy in the object store, the object is deleted too. Backups are not edited in
                        place; they age out on the School's normal backup cycle.
                    </p>
                </section>

                <section id="how-we-protect-it">
                    <h2>7. How we protect it</h2>
                    <p>
                        Reasonable and appropriate safeguards are applied under Section 25 of Republic Act No.
                        10173. In practice this means:
                    </p>
                    <ul>
                        <li>Passwords are stored only as one-way hashes and are never written to a log, an email
                            or an error message.</li>
                        <li>Password-reset and email-verification tokens are single use and are stored only as
                            digests, so a database leak cannot be replayed against a user.</li>
                        <li>All pages are served over HTTPS, and the database connection requires encrypted
                            transport.</li>
                        <li>Every state-changing form is protected by a CSRF token and a
                            Content-Security-Policy, and the portal sets the browser's strict
                            transport-security header.</li>
                        <li>Access to records is restricted by role: a teacher sees the submissions for the
                            sections and subject they teach, and a student sees only their own work.</li>
                        <li>Sign-in attempts, passkey changes and password changes are written to an audit trail.
                            Repeated failures are rate-limited by both IP address and account.</li>
                        <li>Uploaded files are validated by their contents, not just their extension, and are
                            served from keys that are not guessable.</li>
                    </ul>
                    <p>
                        No system is perfectly secure. If a breach affects your information and is likely to
                        cause serious harm, the School will notify you and the National Privacy Commission as
                        required by law.
                    </p>
                </section>

                <section id="your-rights">
                    <h2>8. Your rights</h2>
                    <p>
                        Subject to the conditions in Republic Act No. 10173, you have the right to:
                    </p>
                    <ul>
                        <li><strong>Be informed</strong> about what is being processed, which this notice is.</li>
                        <li><strong>Access</strong> a copy of the information held about you.</li>
                        <li><strong>Correct</strong> inaccurate or incomplete information.</li>
                        <li><strong>Object</strong> to, and withdraw consent to, processing based on consent.</li>
                        <li><strong>Have your data erased</strong>, or block it, or obtain its destruction, where
                            the law permits.</li>
                        <li><strong>Port</strong> your data in a structured, commonly used format.</li>
                        <li><strong>Complain</strong> to the National Privacy Commission if you believe your
                            rights have been violated.</li>
                    </ul>
                    <p>
                        How to exercise a right:
                    </p>
                    <ol>
                        <li>Ask your teacher to correct a mark or a remark from inside the portal — this is the
                            fastest route for academic records.</li>
                        <li>Send a written request for everything else, using the contact details in Section 11.
                            Include enough detail to identify the account and to prove you are the person it
                            belongs to.</li>
                        <li>The School responds within fifteen (15) days of receipt. Where a request is
                            complex, you will be told why more time is needed.</li>
                    </ol>
                    <p>
                        A fee may be charged where a request is manifestly unfounded or excessive, or where it
                        would otherwise interfere with the School's operations. The School will explain any fee
                        before it is charged.
                    </p>
                    <div class="legal-callout">
                        <i class="fas fa-scale-balanced" aria-hidden="true"></i>
                        <p>
                            <strong>If you are not satisfied.</strong> You may lodge a complaint with the
                            National Privacy Commission, whose contact details are published at
                            <strong>privacy.gov.ph</strong>.
                        </p>
                    </div>
                </section>

                <section id="children">
                    <h2>9. Children and students</h2>
                    <p>
                        EduPortal is used by senior high school students, and many of those students are under
                        eighteen. Where a person is a minor, the School obtains the consent of a parent or legal
                        guardian before or alongside the processing described in this notice, as required by
                        law.
                    </p>
                    <p>
                        A few consequences are worth stating plainly:
                    </p>
                    <ul>
                        <li>A parent or guardian may request a copy of the information held about their child, and
                            may ask for it to be corrected, using the same channel as Section 8.</li>
                        <li>Uploaded assignment work may contain personal information about a student's home
                            life. Students are asked not to include it.</li>
                        <li>Only the student's own submissions and grades are visible to the student. Teachers
                            see the submissions for the sections they teach; administrators see the operational
                            records needed to run the portal.</li>
                    </ul>
                </section>

                <section id="changes">
                    <h2>10. Changes to this notice</h2>
                    <p>
                        This notice is reviewed whenever the portal gains a data-handling feature, and at least
                        annually. The "Last updated" date at the top of this page always reflects the current
                        version. Material changes are announced on the portal's login pages before they take
                        effect.
                    </p>
                </section>

                <section id="how-to-contact-us">
                    <h2>11. How to contact us</h2>
                    <p>
                        Questions about this notice, and any request to exercise a right described in Section 8,
                        go to:
                    </p>
                    <ul class="legal-contact-list">
                        <li>
                            <i class="fas fa-school" aria-hidden="true"></i>
                            <span><strong><?php echo legal_e(legal_entity_name()); ?></strong><br>
                                <?php echo legal_e(legal_address()); ?></span>
                        </li>
                        <?php if (legal_contact_email() !== '') : ?>
                            <li>
                                <i class="fas fa-envelope" aria-hidden="true"></i>
                                <span><strong>Email</strong><br>
                                    <a href="mailto:<?php echo legal_e(legal_contact_email()); ?>"><?php echo legal_e(legal_contact_email()); ?></a></span>
                            </li>
                        <?php endif; ?>
                        <?php if (legal_contact_phone() !== '') : ?>
                            <li>
                                <i class="fas fa-phone" aria-hidden="true"></i>
                                <span><strong>Telephone</strong><br><?php echo legal_e(legal_contact_phone()); ?></span>
                            </li>
                        <?php endif; ?>
                        <li>
                            <i class="fas fa-user-shield" aria-hidden="true"></i>
                            <span><strong>Data protection contact</strong><br><?php echo legal_e(legal_officer_name()); ?></span>
                        </li>
                        <li>
                            <i class="fas fa-globe" aria-hidden="true"></i>
                            <span><strong>Online</strong><br>
                                <a href="contact.php">Contact form</a></span>
                        </li>
                    </ul>
                </section>

                <?php render_legal_contact_block(); ?>
                <?php render_legal_nav('privacy.php'); ?>
            </div>
        </div>
    </main>
    <?php render_legal_footer(); ?>
</body>

</html>
