<?php
/**
 * Contact.
 *
 * The working contact channel the portal previously lacked. Every other
 * "contact" string in the install either required a signed-in account
 * (teacher/contact_student.php) or told a visitor to email an administrator
 * whose address was never published.
 *
 * The form posts to this page rather than to a controller, for three reasons
 * that all matter more here than a JSON response would:
 *
 *   - It works with JavaScript disabled, which a legal notice about a public
 *     service should.
 *   - reCAPTCHA v3 hands back a single-use token that expires in two minutes,
 *     so the page it was requested on has to be the page that verifies it.
 *   - A failed submission re-renders with the visitor's own words intact,
 *     which is what makes "your message is too long, shorten it" actionable
 *     rather than a dead end.
 *
 * The response is a single generic success. Nothing here reflects the
 * submitted text back into the page, because a reflected XSS on an
 * unauthenticated form is a credential-harvesting page served from the
 * school's own domain.
 */

require_once __DIR__ . '/libs/legal_page.php';
require_once __DIR__ . '/libs/AuthService.php';
require_once __DIR__ . '/libs/RecaptchaService.php';

/**
 * The closed list. An open value would let a caller label a message as
 * anything, which defeats filtering the inbox by topic.
 */
$topics = [
    'account' => 'Account access — sign-in, password, passkey',
    'academic' => 'Grades, submissions or assignments',
    'privacy' => 'Privacy, or a request about my data',
    'technical' => 'Something is not working',
    'other' => 'Something else',
];

$success = false;
$error = '';
$fieldErrors = [];

$name = '';
$email = '';
$message = '';
$topic = 'account';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $topic = (string) ($_POST['topic'] ?? 'account');
    $honeypot = trim((string) ($_POST['website'] ?? ''));

    if (!array_key_exists($topic, $topics)) {
        $topic = 'account';
    }

    // Honeypot first, and silently. A real visitor never sees this field, so
    // reporting the hit would be a free signal to a bot that it was caught.
    $trapFilled = $honeypot !== '';

    try {
        $conn = getDBConnection();
    } catch (Throwable $exception) {
        error_log('EduPortal contact form: database unavailable: ' . $exception->getMessage());
        $conn = null;
        $error = 'The contact form is temporarily unavailable. Please try again later.';
    }

    if ($conn === null) {
        // Nothing below can run; the error is already set.
    } elseif ($trapFilled) {
        $error = 'Your message could not be sent. Please try again.';
    } elseif (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired before the message was sent. Please reload the page and try again.';
    } else {
        $buckets = [auth_rate_limit_bucket_key('contact', auth_client_ip())];
        $lockedFor = auth_rate_limit_check($conn, $buckets);

        if ($lockedFor !== null && $lockedFor > 0) {
            $error = 'Too many messages have been sent from this connection. Please try again later.';
        } else {
            $recaptcha = recaptcha_check($_POST, 'contact', [
                'conn' => $conn,
                'user_role' => 'public',
            ]);

            if (!$recaptcha['ok']) {
                $error = 'Your message could not be verified as coming from a person. Please try again.';
            } else {
                if ($name === '' || mb_strlen($name) > 150) {
                    $fieldErrors['name'] = 'Enter your name (up to 150 characters).';
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
                    $fieldErrors['email'] = 'Enter an email address we can reply to.';
                }
                if ($message === '') {
                    $fieldErrors['message'] = 'Enter a message.';
                } elseif (mb_strlen($message) > 5000) {
                    $fieldErrors['message'] = 'Your message is too long. Keep it under 5000 characters.';
                } elseif (preg_match('/\n\s{40,}|(\S+@\S+){4,}/', $message) === 1) {
                    // The oldest header-injection shape there is. Nothing here
                    // sends mail today, but a stored row is one query away from
                    // being quoted into a notification, so the value is
                    // normalised on the way in rather than on the way out.
                    $fieldErrors['message'] = 'Your message contains formatting we cannot accept. Please remove it and try again.';
                }

                if ($fieldErrors !== []) {
                    $error = 'Please correct the highlighted fields.';
                } else {
                    try {
                        $stmt = $conn->prepare(
                            'INSERT INTO contact_messages (name, email, topic, message, ip_address, user_agent)'
                            . ' VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        $ip = auth_client_ip();
                        $agent = auth_client_user_agent();
                        $stmt->execute([
                            $name,
                            $email,
                            $topic,
                            $message,
                            $ip !== '' ? $ip : null,
                            $agent !== '' ? $agent : null,
                        ]);

                        auth_rate_limit_clear($conn, $buckets, null);
                        auth_record_event($conn, 'contact_request', 'accepted', [
                            'user_role' => 'public',
                            'detail' => 'topic=' . $topic,
                        ]);

                        $success = true;
                        // Cleared so a reload cannot re-post the same message,
                        // and so the success screen does not repeat back the
                        // visitor's own words.
                        $name = '';
                        $email = '';
                        $message = '';
                        $topic = 'account';
                    } catch (Throwable $exception) {
                        error_log('EduPortal contact form: insert failed: ' . $exception->getMessage());
                        $error = 'The contact form is temporarily unavailable. Please try again later.';
                    }
                }
            }
        }
    }
}

/**
 * Prefer a topic that survived validation, so a rejected submission comes back
 * with the visitor's own choice selected rather than the default.
 */
$selectedTopic = isset($topics[$topic]) ? $topic : 'account';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php render_legal_head(
        'Contact',
        'Contact ' . legal_entity_name() . ' about EduPortal: account access, grades and submissions, '
        . 'a privacy or data request, or a problem with the portal.'
    ); ?>
</head>

<body>
    <a class="skip-link" href="#main-content">Skip to the contact form</a>
    <main class="legal-shell" id="main-content">
        <?php render_legal_topbar(); ?>
        <?php render_legal_masthead(
            'fa-envelope',
            'Legal notice',
            'Contact',
            'A working channel to reach ' . legal_entity_name() . ' about EduPortal. Use this for account '
            . 'access, a question about a grade or a submission, a request about your personal data, or a '
            . 'problem with the portal.'
        ); ?>

        <?php render_legal_unconfigured_notice(); ?>

        <div class="legal-layout">
            <?php render_legal_toc([
                'send-a-message' => 'Send a message',
                'other-ways' => 'Other ways to reach us',
                'what-to-expect' => 'What to expect',
                'your-rights' => 'Your privacy rights',
            ]); ?>

            <div class="legal-body">
                <section id="send-a-message">
                    <h2>Send a message</h2>
                    <p>
                        The form below is answered by the portal administrator. Include your section or your
                        account details where it helps, but please do not send a password — no one will ask for
                        one, and a message containing one will be deleted unread.
                    </p>

                    <?php if ($success) : ?>
                        <div class="legal-callout" role="status" style="border-color: rgba(16, 185, 129, .3); background: rgba(16, 185, 129, .08);">
                            <i class="fas fa-circle-check" aria-hidden="true" style="color: var(--success-color);"></i>
                            <p>
                                <strong>Your message has been received.</strong> We will reply to the address
                                you gave us. If you do not hear back within fifteen (15) days, check your spam
                                folder before sending it again.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($error !== '') : ?>
                        <div class="legal-callout legal-callout--warning" role="alert">
                            <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                            <p><?php echo legal_e($error); ?></p>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="contact.php" data-loader="true" data-recaptcha-action="contact"
                        novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo legal_e(csrf_token()); ?>">
                        <div class="legal-trap" aria-hidden="true">
                            <label for="website">Leave this field empty</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="legal-field">
                            <label for="topic">What is this about? <span aria-hidden="true"
                                    style="color: var(--danger-color);">*</span></label>
                            <select id="topic" name="topic" class="premium-input" required>
                                <?php foreach ($topics as $value => $label) : ?>
                                    <option value="<?php echo legal_e($value); ?>"
                                        <?php echo $selectedTopic === $value ? 'selected' : ''; ?>><?php echo legal_e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="legal-field">
                            <label for="name">Your name <span aria-hidden="true"
                                    style="color: var(--danger-color);">*</span></label>
                            <input type="text" id="name" name="name" class="premium-input" required
                                autocomplete="name" maxlength="150"
                                value="<?php echo legal_e($name); ?>"
                                <?php echo isset($fieldErrors['name']) ? 'aria-invalid="true" aria-describedby="name-error"' : ''; ?>>
                            <?php if (isset($fieldErrors['name'])) : ?>
                                <p class="legal-field__help" id="name-error" role="alert"
                                    style="color: var(--danger-color);"><?php echo legal_e($fieldErrors['name']); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="legal-field">
                            <label for="email">Your email address <span aria-hidden="true"
                                    style="color: var(--danger-color);">*</span></label>
                            <input type="email" id="email" name="email" class="premium-input" required
                                autocomplete="email" maxlength="255"
                                value="<?php echo legal_e($email); ?>"
                                <?php echo isset($fieldErrors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : ''; ?>>
                            <p class="legal-field__help" id="email-hint">We use this only to reply to you.</p>
                            <?php if (isset($fieldErrors['email'])) : ?>
                                <p class="legal-field__help" role="alert" style="color: var(--danger-color);"><?php echo legal_e($fieldErrors['email']); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="legal-field">
                            <label for="message">Your message <span aria-hidden="true"
                                    style="color: var(--danger-color);">*</span></label>
                            <textarea id="message" name="message" class="premium-input" rows="7" required
                                maxlength="5000" aria-describedby="message-hint"
                                <?php echo isset($fieldErrors['message']) ? 'aria-invalid="true" aria-describedby="message-hint message-error"' : ''; ?>><?php echo legal_e($message); ?></textarea>
                            <p class="legal-field__help" id="message-hint">Up to 5000 characters. Do not include a
                                password.</p>
                            <?php if (isset($fieldErrors['message'])) : ?>
                                <p class="legal-field__help" id="message-error" role="alert"
                                    style="color: var(--danger-color);"><?php echo legal_e($fieldErrors['message']); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="legal-actions">
                            <button type="submit" class="premium-btn premium-btn-primary">
                                <i class="fas fa-paper-plane" aria-hidden="true"></i> Send message
                            </button>
                            <span style="color: var(--text-muted); font-size: .8rem;">Fields marked
                                <span aria-hidden="true" style="color: var(--danger-color);">*</span> are
                                required.</span>
                        </div>
                    </form>
                </section>

                <section id="other-ways">
                    <h2>Other ways to reach us</h2>
                    <?php if (legal_contact_email() !== '' || legal_contact_phone() !== '') : ?>
                        <ul class="legal-contact-list">
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
                        </ul>
                    <?php else : ?>
                        <p>
                            No email address or telephone number is published for this portal yet. Use the form
                            above, or reach the portal through the School's main office.
                        </p>
                    <?php endif; ?>
                    <p>
                        Already have an account and the message is about a specific assignment, a grade or a
                        teacher's feedback? Raise it inside the portal first — the teacher who can act on it
                        sees the message in context, and a conversation that stays on the record is easier to
                        resolve than an email thread.
                    </p>
                </section>

                <section id="what-to-expect">
                    <h2>What to expect</h2>
                    <ul>
                        <li>Requests are answered within <strong>fifteen (15) days</strong>, as required by
                            Republic Act No. 10173.</li>
                        <li>A request that needs more time will be acknowledged with a reason and a new
                            date.</li>
                        <li>You will be asked to prove you are the person the record belongs to. We will never
                            ask for your password.</li>
                        <li>For a correction to a mark, the fastest route is to ask your teacher from inside
                            the portal.</li>
                        <li>Messages are stored, not emailed, and are kept only until the matter is
                            resolved.</li>
                    </ul>
                </section>

                <section id="your-rights">
                    <h2>Your privacy rights</h2>
                    <p>
                        Everything sent through this form is personal information handled under the
                        <a href="privacy.php">Privacy Policy</a>. In particular, we use what you send to answer
                        you and nothing else, we do not add you to a mailing list, and you may ask at any time
                        for a copy of it or for it to be deleted once the matter is closed.
                    </p>
                </section>

                <?php render_legal_nav('contact.php'); ?>
            </div>
        </div>
    </main>
    <?php echo recaptcha_script_tag(); ?>
    <?php render_legal_footer(); ?>
</body>

</html>
