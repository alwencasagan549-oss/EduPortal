<?php
/**
 * Terms of Service.
 *
 * The counterpart to privacy.php, and the document a school's legal reviewer
 * will read first. It is written to the actual behaviour of the portal: the
 * accept-it-once-by-using-it model, the file size limit the upload UI enforces,
 * and the 9 MB replacement limit in the assignment form.
 */

require_once __DIR__ . '/libs/legal_page.php';

$toc = [
    'acceptance' => 'Acceptance of these terms',
    'the-service' => 'The service',
    'accounts' => 'Accounts and eligibility',
    'your-conduct' => 'Your responsibilities',
    'academic-work' => 'Academic work and content',
    'acceptable-use' => 'Acceptable use',
    'availability' => 'Availability and changes',
    'intellectual-property' => 'Intellectual property',
    'disclaimers' => 'Disclaimers',
    'liability' => 'Limitation of liability',
    'termination' => 'Suspension and termination',
    'privacy' => 'Privacy',
    'governing-law' => 'Governing law',
    'changes' => 'Changes to these terms',
    'contact' => 'Contact',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php render_legal_head(
        'Terms of Service',
        'The rules for using the EduPortal assignment and learning management portal of '
        . legal_entity_name() . ', including acceptable use, academic work and liability.'
    ); ?>
</head>

<body>
    <a class="skip-link" href="#main-content">Skip to the terms</a>
    <main class="legal-shell" id="main-content">
        <?php render_legal_topbar(); ?>
        <?php render_legal_masthead(
            'fa-file-contract',
            'Legal notice',
            'Terms of Service',
            'These terms govern the use of EduPortal, the assignment and learning management portal operated by '
            . legal_entity_name() . '. By creating an account or using the portal you agree to them.'
        ); ?>

        <?php render_legal_unconfigured_notice(); ?>

        <div class="legal-layout">
            <?php render_legal_toc($toc); ?>

            <div class="legal-body">
                <section id="acceptance">
                    <h2>1. Acceptance of these terms</h2>
                    <p>
                        These terms apply to every person who accesses EduPortal, including students, teachers,
                        administrators and any visitor who registers an account. By signing up, signing in, or
                        otherwise using the portal you agree to these terms and to our
                        <a href="privacy.php">Privacy Policy</a>. If you do not agree, do not use the portal.
                    </p>
                    <p>
                        Where these terms conflict with a law, a DepEd issuance, or a written instruction from the
                        School, the law and the School's instruction prevail and the remainder of these terms
                        continues to apply.
                    </p>
                </section>

                <section id="the-service">
                    <h2>2. The service</h2>
                    <p>
                        EduPortal is an internal academic tool. It lets teachers publish assignments and
                        materials to a class, lets students upload work, and lets teachers return marks and
                        remarks. It is offered free of charge to the School's own students and employees and is
                        not a general-purpose public service.
                    </p>
                    <p>The portal currently provides:</p>
                    <ul>
                        <li>Assignment publishing targeted by grade level, strand and section.</li>
                        <li>File submission, including resumable upload for larger files.</li>
                        <li>Marking and feedback, visible to the submitting student.</li>
                        <li>In-app notifications when an assignment is published or graded.</li>
                        <li>Account security: password sign-in, passkeys, and rate-limited recovery.</li>
                        <li>Installable as a progressive web app, with an offline shell for the pages already
                            loaded.</li>
                    </ul>
                    <p>
                        Features may be added, changed or withdrawn. The portal is not a substitute for the
                        School's official records; where the portal and an official record disagree, the official
                        record is correct.
                    </p>
                </section>

                <section id="accounts">
                    <h2>3. Accounts and eligibility</h2>
                    <ul>
                        <li><strong>Students</strong> may register with a Learner Reference Number issued by the
                            School. A student account is for that student only.</li>
                        <li><strong>Teachers</strong> may register with an email address and the subject they
                            teach. Where the School operates an approval workflow, a teacher account stays
                            inactive until an administrator activates it.</li>
                        <li>One person may hold one account per role. Account credentials are not transferable and
                            may not be shared.</li>
                        <li>You are responsible for everything done through your account. Tell the School
                            immediately if you think someone else has used it.</li>
                        <li>You must give accurate information at sign-up and keep it accurate. A wrong grade
                            level or section means work is posted to the wrong class.</li>
                    </ul>
                    <p>
                        The School may suspend or close an account that is created with false information, used
                        by someone other than its owner, or used to disrupt the portal for others.
                    </p>
                </section>

                <section id="your-conduct">
                    <h2>4. Your responsibilities</h2>
                    <p>You agree to:</p>
                    <ul>
                        <li>Use the portal only for genuine academic purposes connected to your enrolment or
                            employment at the School.</li>
                        <li>Keep your password secure and use a passkey where one is available. Do not let another
                            person sign in as you, even a classmate.</li>
                        <li>Report a lost device, a suspected compromise, or an error in a mark as soon as you
                            notice it.</li>
                        <li>Check that an assignment is addressed to your section before submitting, and use
                            your own account to submit your own work.</li>
                        <li>Follow the instructions of your teacher and the School's policies on academic
                            integrity, including the school's rules on plagiarism and collusion.</li>
                    </ul>
                </section>

                <section id="academic-work">
                    <h2>5. Academic work and content</h2>
                    <p>
                        You keep ownership of the work you create and upload. You grant the School a limited,
                        non-exclusive, royalty-free licence to host, reproduce, display and transmit that work
                        <em>for the sole purpose of operating this portal</em> — storing it, letting the
                        teacher who set the assignment see it, and letting your teacher return marks and
                        remarks. That licence ends when the School deletes the work under its retention schedule.
                    </p>
                    <p>
                        <strong>Academic integrity is your responsibility.</strong> Submitting work that is not
                        your own, or that was generated or rewritten by a tool in a way your teacher has not
                        permitted, may be treated as academic misconduct. The portal's automatic spell-checking,
                        formatting and upload tooling exists to make submission easier, not to make authorship
                        any less yours. If you are unsure whether a tool is permitted, ask your teacher first.
                    </p>
                    <p>
                        You must not upload material you have no right to upload: copyrighted work belonging to
                        someone else, material that infringes anyone's privacy, or anything unlawful, defamatory,
                        discriminatory, or hateful. Do not upload another person's personal information unless
                        you are required to and entitled to.
                    </p>
                    <p>
                        The School may remove content that breaches these terms, and may be required to do so by
                        law or by a valid order.
                    </p>
                </section>

                <section id="acceptable-use">
                    <h2>6. Acceptable use</h2>
                    <p>You must not, and must not attempt to:</p>
                    <ul>
                        <li>Probe, scan or test the vulnerability of the portal or any system it depends on,
                            without written authorisation from the School.</li>
                        <li>Use automated tools, scripts or bots to sign up, sign in, submit work, or harvest
                            any information, including anything that evades the anti-abuse checks.</li>
                        <li>Bypass, disable or interfere with the rate limits, the human-verification check, the
                            file-type checks, or any other access control.</li>
                        <li>Access another person's account, marks, submissions or messages, or attempt to.</li>
                        <li>Upload malicious code, or any file whose contents are executable or whose purpose is
                            to damage, overload or gain unauthorised access.</li>
                        <li>Copy, scrape, mirror or redistribute the portal's software, design, or content for
                            commercial use, or to build a competing service.</li>
                        <li>Reverse engineer the portal except to the extent that this is expressly permitted by
                            applicable law, which cannot be waived.</li>
                    </ul>
                    <p>
                        Attempts to breach these terms are recorded in the portal's security audit trail, and may
                        be referred to the School's administration, to law enforcement, or to the National Privacy
                        Commission where personal information is involved.
                    </p>
                </section>

                <section id="availability">
                    <h2>7. Availability and changes</h2>
                    <p>
                        The portal is provided on an "as available" basis. The School aims for high availability
                        but does not promise that the portal will be uninterrupted or error-free. Availability
                        may be affected by scheduled maintenance, network or power failures, upload service
                        limits, and circumstances outside the School's control.
                    </p>
                    <p>
                        The School may modify, suspend or discontinue any part of the portal, including
                        withdrawing a feature, and may change storage and file size limits. Where a change
                        materially affects your use, the School will give reasonable notice.
                    </p>
                    <p>
                        <strong>Academic deadlines are not extended automatically</strong> by an outage. If the
                        portal is unavailable when work is due, tell your teacher.
                    </p>
                </section>

                <section id="intellectual-property">
                    <h2>8. Intellectual property</h2>
                    <p>
                        The portal's software, interface, design, branding and documentation are owned by the
                        School or its developer and are protected by copyright and other intellectual property
                        law. You may use the portal as a student or employee of the School; nothing else is
                        granted.
                    </p>
                    <p>
                        Third-party names and marks — including Google Analytics and reCAPTCHA, and any
                        third-party material embedded in a submission — remain the property of their respective
                        owners and are used under their own terms. A link to or an appearance of a third-party
                        name does not imply endorsement.
                    </p>
                </section>

                <section id="disclaimers">
                    <h2>9. Disclaimers</h2>
                    <p>
                        Except where applicable law cannot be excluded, the portal is provided "as is" and "as
                        available", without warranties of any kind, whether express or implied, including any
                        implied warranty of merchantability, fitness for a particular purpose, or
                        non-infringement. The School does not warrant that the portal will be uninterrupted,
                        error-free, or free of harmful components, or that a mark or remark recorded in it is
                        correct in the eyes of anyone other than the portal.
                    </p>
                    <p>
                        Nothing in these terms excludes or limits liability for death or personal injury caused
                        by negligence, for fraud or fraudulent misrepresentation, or for any other liability that
                        cannot lawfully be excluded.
                    </p>
                </section>

                <section id="liability">
                    <h2>10. Limitation of liability</h2>
                    <p>
                        Subject to the preceding section, no School officer, employee, volunteer or contractor is
                        liable for any indirect or consequential loss, or for loss of profit, data, goodwill or
                        opportunity, arising from your use of the portal or its unavailability. The School's
                        total aggregate liability to you for claims relating to the portal is limited to PHP
                        1,000 or the amount you actually paid to use the portal, whichever is greater, except
                        where the law provides otherwise.
                    </p>
                    <p>
                        This clause does not apply to a claim arising from the School's wilful misconduct or gross
                        negligence, or to a data-subject's statutory rights under the Data Privacy Act, which
                        cannot be contracted away.
                    </p>
                </section>

                <section id="termination">
                    <h2>11. Suspension and termination</h2>
                    <p>
                        The School may suspend access to an account immediately where continued access creates a
                        security risk, an active investigation, or disruption for other users. Access is also ended
                        when you graduate, transfer, or leave the School's employment.
                    </p>
                    <p>
                        You may stop using the portal at any time. On request, your account can be closed and
                        your data deleted, subject to the retention obligations in Section 6 of the
                        <a href="privacy.php">Privacy Policy</a>. Academic records that the School is legally
                        required to keep are retained on that basis rather than on yours.
                    </p>
                </section>

                <section id="privacy">
                    <h2>12. Privacy</h2>
                    <p>
                        Processing of personal information is governed by the
                        <a href="privacy.php">Privacy Policy</a>, which forms part of these terms. Nothing in
                        these terms overrides your rights under Republic Act No. 10173.
                    </p>
                </section>

                <section id="governing-law">
                    <h2>13. Governing law</h2>
                    <p>
                        These terms are governed by the laws of the Republic of the Philippines. The courts of
                        the Philippines have exclusive jurisdiction over any dispute arising from them, subject to
                        any mandatory forum that a statute or the National Privacy Commission imposes.
                    </p>
                </section>

                <section id="changes">
                    <h2>14. Changes to these terms</h2>
                    <p>
                        The School may revise these terms. The "Last updated" date at the top of this page always
                        reflects the current version. Continued use of the portal after a revision takes effect
                        means you accept the revised terms; if you do not, stop using the portal and contact the
                        School. Material changes are announced on the portal's login pages before they take
                        effect.
                    </p>
                </section>

                <section id="contact">
                    <h2>15. Contact</h2>
                    <p>
                        Questions about these terms, or a request relating to your rights, go to:
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
                            <i class="fas fa-globe" aria-hidden="true"></i>
                            <span><strong>Online</strong><br>
                                <a href="contact.php">Contact form</a></span>
                        </li>
                    </ul>
                </section>

                <?php render_legal_nav('terms.php'); ?>
            </div>
        </div>
    </main>
    <?php render_legal_footer(); ?>
</body>

</html>
