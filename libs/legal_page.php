<?php
/**
 * Shared shell for the public legal pages: privacy.php, terms.php, contact.php.
 *
 * These three pages are read by parents, students, teachers and the National
 * Privacy Commission rather than by users chasing an assignment, so they are
 * deliberately independent of the sidebar shell in libs/navigation.php. They
 * are readable without an account, they must stay readable when the database
 * is unreachable, and they must not depend on a session.
 *
 * The school-specific details -- who is the controller, where to write, how to
 * ask for a copy of your data -- are read from the environment once, here,
 * rather than being typed into three pages. A policy that names the wrong
 * address is worse than no policy, so a deployment that forgets to set
 * LEGAL_CONTACT_EMAIL gets a visibly unconfigured state rather than a silent
 * fallback to an address nobody reads.
 *
 *   LEGAL_ENTITY_NAME     the school, as it should appear in the policy
 *   LEGAL_CONTACT_EMAIL   the address a data subject writes to
 *   LEGAL_CONTACT_PHONE   optional; omitted entirely when unset
 *   LEGAL_ADDRESS         postal address, required by RA 10173 notices
 *   LEGAL_OFFICER_NAME    the person or office that receives data requests
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Environment first, then a define() of the same name, so config/credentials.php
 * behaves the way the database configuration already does. An unset or blank
 * value falls through to $default rather than becoming an empty string that
 * renders as a blank line in the middle of a legal notice.
 */
function legal_setting(string $name, string $default = ''): string
{
    $value = getenv($name);

    if ($value === false || trim($value) === '') {
        $value = defined($name) ? (string) constant($name) : '';
    }

    $value = trim($value);

    return $value !== '' ? $value : $default;
}

function legal_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function legal_entity_name(): string
{
    return legal_setting('LEGAL_ENTITY_NAME', 'Ruben E. Ecleo Sr. National High School');
}

/**
 * No default. An unset address is a deployment mistake, and the pages say so
 * loudly rather than publishing a mailbox that does not exist. Falls back to
 * the SMTP sender, which is at least an address this deployment already sends
 * from, before giving up.
 */
function legal_contact_email(): string
{
    return legal_setting('LEGAL_CONTACT_EMAIL', legal_setting('MAIL_FROM'));
}

function legal_contact_phone(): string
{
    return legal_setting('LEGAL_CONTACT_PHONE');
}

function legal_address(): string
{
    return legal_setting('LEGAL_ADDRESS', 'Ruben E. Ecleo Sr. National High School, Philippines');
}

function legal_officer_name(): string
{
    return legal_setting('LEGAL_OFFICER_NAME', 'the Data Protection Officer of ' . legal_entity_name());
}

/**
 * The date the notice was last revised. Kept as a constant rather than a
 * per-page value so a revision to one is obviously a revision to all three.
 */
function legal_last_updated(): string
{
    return legal_setting('LEGAL_LAST_UPDATED', '1 September 2026');
}

/**
 * The head of a legal document.
 *
 * Deliberately minimal: the stylesheet is loaded normally rather than through
 * the preload trick index.php uses, because these pages have no LCP image to
 * protect and a legal notice should be readable on the first paint.
 */
function render_legal_head(string $title, string $description): void
{
    $fullTitle = $title . ' | ' . legal_setting('PLATFORM_NAME', 'EduPortal LMS');
    $canonical = rtrim((string) SITE_URL, '/') . '/' . basename($_SERVER['SCRIPT_NAME'] ?? '');
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo google_analytics_tag(); ?>
    <title><?php echo legal_e($fullTitle); ?></title>
    <meta name="description" content="<?php echo legal_e($description); ?>">
    <link rel="canonical" href="<?php echo legal_e($canonical); ?>">
    <link rel="icon" href="assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="assets/style.min.css?v=20260924">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </noscript>
    <script src="assets/js/trusted_types.js"></script>
    <style>
        .legal-shell {
            max-width: 1000px;
            margin: 0 auto;
            padding: 3rem 1.5rem 4rem;
        }
        .legal-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            padding: 1.25rem 1.5rem;
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            background: var(--glass-bg);
            backdrop-filter: blur(18px);
            margin-bottom: 2rem;
        }
        .legal-topbar a {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            color: var(--text-muted);
            font-size: .9rem;
            font-weight: 600;
            text-decoration: none;
            min-height: 44px;
            transition: color .2s;
        }
        .legal-topbar a:hover,
        .legal-topbar a:focus-visible {
            color: var(--primary-color);
        }
        .legal-masthead {
            padding: 2.5rem 2rem;
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            background: var(--glass-bg);
            backdrop-filter: blur(18px);
            margin-bottom: 2rem;
        }
        .legal-masthead h1 {
            font-size: clamp(1.8rem, 4vw, 2.6rem);
            font-weight: 800;
            letter-spacing: -.5px;
            margin: .75rem 0 1rem;
        }
        .legal-masthead p {
            color: var(--text-muted);
            font-size: 1rem;
            max-width: 68ch;
        }
        .legal-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            background: rgba(78, 115, 223, .15);
            border: 1px solid rgba(78, 115, 223, .25);
        }
        .legal-icon i {
            font-size: 1.5rem;
            color: #91a8ff;
        }
        .legal-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-top: 1.5rem;
        }
        .legal-layout {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 2rem;
            align-items: start;
        }
        .legal-toc {
            position: sticky;
            top: 1.5rem;
            padding: 1.25rem;
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            background: var(--glass-bg);
        }
        .legal-toc h2 {
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        .legal-toc ol {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: .15rem;
            counter-reset: legal-toc;
        }
        .legal-toc li { counter-increment: legal-toc; }
        .legal-toc a {
            display: flex;
            gap: .6rem;
            align-items: baseline;
            padding: .45rem .5rem;
            border-radius: 8px;
            color: var(--text-muted);
            font-size: .875rem;
            line-height: 1.4;
            text-decoration: none;
            transition: background .2s, color .2s;
        }
        .legal-toc a::before {
            content: counter(legal-toc) ".";
            font-variant-numeric: tabular-nums;
            color: #91a8ff;
            font-weight: 700;
            flex-shrink: 0;
        }
        .legal-toc a:hover,
        .legal-toc a:focus-visible {
            background: rgba(255, 255, 255, .05);
            color: var(--text-main);
        }
        .legal-body {
            padding: 2.5rem 2.25rem;
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            background: var(--glass-bg);
        }
        .legal-body section + section {
            margin-top: 2.5rem;
            padding-top: 2.5rem;
            border-top: 1px solid var(--glass-border);
        }
        .legal-body h2 {
            font-size: 1.25rem;
            font-weight: 800;
            margin-bottom: 1rem;
            scroll-margin-top: 1.5rem;
        }
        .legal-body h3 {
            font-size: 1rem;
            font-weight: 700;
            margin: 1.5rem 0 .5rem;
            color: var(--text-main);
        }
        .legal-body p,
        .legal-body li {
            color: var(--text-muted);
            font-size: .95rem;
            line-height: 1.75;
        }
        .legal-body p + p { margin-top: .85rem; }
        .legal-body ul,
        .legal-body ol {
            margin: .85rem 0 0;
            padding-left: 1.25rem;
            display: flex;
            flex-direction: column;
            gap: .5rem;
        }
        .legal-body a {
            color: #91a8ff;
            text-decoration: underline;
            text-underline-offset: 2px;
        }
        .legal-body strong { color: var(--text-main); }
        .legal-table-wrap {
            margin-top: 1rem;
            overflow-x: auto;
            border: 1px solid var(--glass-border);
            border-radius: 12px;
        }
        .legal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
            min-width: 560px;
        }
        .legal-table th,
        .legal-table td {
            padding: .8rem 1rem;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid var(--glass-border);
        }
        .legal-table th {
            color: var(--text-main);
            font-weight: 700;
            font-size: .8rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            background: rgba(255, 255, 255, .03);
        }
        .legal-table td { color: var(--text-muted); }
        .legal-table tr:last-child td { border-bottom: none; }
        .legal-callout {
            display: flex;
            gap: .85rem;
            align-items: flex-start;
            padding: 1.1rem 1.25rem;
            border-radius: 12px;
            border: 1px solid rgba(78, 115, 223, .25);
            background: rgba(78, 115, 223, .08);
            margin-top: 1.5rem;
        }
        .legal-callout i {
            color: #91a8ff;
            margin-top: .2rem;
            flex-shrink: 0;
        }
        .legal-callout p { color: var(--text-muted); font-size: .9rem; }
        .legal-callout--warning {
            border-color: rgba(245, 158, 11, .3);
            background: rgba(245, 158, 11, .08);
        }
        .legal-callout--warning i { color: var(--warning-color); }
        .legal-nav {
            display: flex;
            gap: .75rem;
            flex-wrap: wrap;
            margin-top: 2.5rem;
            padding-top: 2rem;
            border-top: 1px solid var(--glass-border);
        }
        .legal-nav a {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            min-height: 44px;
            padding: .6rem 1rem;
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-muted);
            font-size: .875rem;
            font-weight: 600;
            text-decoration: none;
            transition: border-color .2s, color .2s;
        }
        .legal-nav a:hover,
        .legal-nav a:focus-visible {
            border-color: var(--primary-color);
            color: var(--text-main);
        }
        .legal-field { margin-top: 1.25rem; }
        .legal-field label {
            display: block;
            font-size: .875rem;
            font-weight: 700;
            margin-bottom: .5rem;
        }
        .legal-field__help {
            font-size: .8rem;
            color: var(--text-muted);
            margin-top: .4rem;
        }
        .legal-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 2rem;
        }
        /* The honeypot is a real input that must stay reachable to a screen
           reader and a keyboard, and only be invisible to a human looking at
           the page. display:none would be announced and focusable-but-lost. */
        .legal-trap {
            position: absolute !important;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .legal-contact-list {
            list-style: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 1rem;
        }
        .legal-contact-list li {
            display: flex;
            gap: .85rem;
            align-items: flex-start;
        }
        .legal-contact-list i {
            color: #91a8ff;
            margin-top: .35rem;
            flex-shrink: 0;
        }
        @media (max-width: 900px) {
            .legal-layout { grid-template-columns: minmax(0, 1fr); }
            .legal-toc { position: static; }
        }
        @media (max-width: 600px) {
            .legal-shell { padding: 1.5rem 1.1rem 3rem; }
            .legal-masthead, .legal-body { padding: 1.75rem 1.25rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
<?php
}

/**
 * The back bar above the notice. "Back to home" is a link rather than
 * history.back(), because a visitor who followed a search result has no
 * in-page history to go back to.
 */
function render_legal_topbar(string $homeLabel = 'EduPortal home'): void
{
    ?>
    <div class="legal-topbar">
        <a href="index.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to <?php echo legal_e($homeLabel); ?></a>
        <a href="EDUPORTAL_TEACHER_STUDENT_GUIDE.html"><i class="fas fa-book-open" aria-hidden="true"></i>
            User guide</a>
    </div>
    <?php
}

/**
 * $toc maps a section id to its heading. Kept as data rather than parsed out of
 * the markup: a table of contents that silently drops an entry when a heading is
 * reworded is worse than none.
 */
function render_legal_toc(array $toc, string $heading = 'On this page'): void
{
    if ($toc === []) {
        return;
    }
    ?>
    <nav class="legal-toc" aria-labelledby="legal-toc-heading">
        <h2 id="legal-toc-heading"><?php echo legal_e($heading); ?></h2>
        <ol>
            <?php foreach ($toc as $id => $label): ?>
                <li><a href="#<?php echo legal_e((string) $id); ?>"><?php echo legal_e((string) $label); ?></a></li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
}

/**
 * The three notices, linked from every page so one is never only reachable from
 * a page nobody visits.
 */
function render_legal_nav(string $current): void
{
    $pages = [
        'privacy.php' => ['Privacy Policy', 'fa-shield-halved'],
        'terms.php' => ['Terms of Service', 'fa-file-contract'],
        'contact.php' => ['Contact', 'fa-envelope'],
    ];
    ?>
    <nav class="legal-nav" aria-label="Legal and contact">
        <?php foreach ($pages as $href => [$label, $icon]): ?>
            <?php if ($href === $current) { continue; } ?>
            <a href="<?php echo legal_e($href); ?>">
                <i class="fas <?php echo legal_e($icon); ?>" aria-hidden="true"></i> <?php echo legal_e($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function render_legal_masthead(string $icon, string $eyebrow, string $title, string $lede): void
{
    ?>
    <header class="legal-masthead">
        <div class="legal-icon" aria-hidden="true"><i class="fas <?php echo legal_e($icon); ?>"></i></div>
        <span class="premium-badge badge-blue" style="margin-top: 1.25rem;"><?php echo legal_e($eyebrow); ?></span>
        <h1><?php echo legal_e($title); ?></h1>
        <p><?php echo legal_e($lede); ?></p>
        <div class="legal-meta">
            <span class="premium-badge badge-blue"><i class="fas fa-building-columns" aria-hidden="true"
                    style="margin-right: .4rem;"></i> <?php echo legal_e(legal_entity_name()); ?></span>
            <span class="premium-badge badge-green"><i class="fas fa-calendar-check" aria-hidden="true"
                    style="margin-right: .4rem;"></i> Last updated <?php echo legal_e(legal_last_updated()); ?></span>
        </div>
    </header>
    <?php
}

/**
 * The contact block, reused at the foot of the privacy and terms notices so
 * "how do I exercise this right" is one click away from the right itself.
 */
function render_legal_contact_block(): void
{
    $email = legal_contact_email();
    ?>
    <div class="legal-callout">
        <i class="fas fa-address-book" aria-hidden="true"></i>
        <p>
            <strong>Exercising this notice.</strong>
            Write to
            <?php if ($email !== '') : ?>
                <a href="mailto:<?php echo legal_e($email); ?>"><?php echo legal_e($email); ?></a>
            <?php else : ?>
                <em>the address published on the
                    <a href="contact.php">contact page</a></em>
            <?php endif; ?>,
            or use the <a href="contact.php">contact form</a>. Requests are answered
            within fifteen (15) days, as required by Republic Act No. 10173.
        </p>
    </div>
    <?php
}

/**
 * Shown only when no contact address is configured. A policy that points at a
 * mailbox nobody reads is a broken promise, and the operator needs to see that
 * at the top of the page rather than discover it in a complaint.
 */
function render_legal_unconfigured_notice(): void
{
    if (legal_contact_email() !== '') {
        return;
    }
    ?>
    <div class="legal-callout legal-callout--warning">
        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
        <p>
            <strong>Site administrator:</strong> set <code>LEGAL_CONTACT_EMAIL</code> (and, for a complete
            notice, <code>LEGAL_CONTACT_PHONE</code>, <code>LEGAL_ADDRESS</code> and
            <code>LEGAL_OFFICER_NAME</code>) before publishing this page. Until then the contact
            details below are incomplete and the notice is not compliant.
        </p>
    </div>
    <?php
}

/**
 * The document footer. Copyright line, contact address, and the scripts the
 * rest of the portal loads, so a legal page behaves like every other page in
 * the install (PWA manifest, loader, human gate).
 */
function render_legal_footer(): void
{
    $email = legal_contact_email();
    $phone = legal_contact_phone();
    ?>
    <footer style="margin-top: 3rem; padding: 2rem 1.5rem; border-top: 1px solid var(--glass-border);">
        <div style="max-width: 1000px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 1.5rem; justify-content: space-between; align-items: center;">
            <p style="color: var(--text-muted); font-size: .85rem;">
                &copy; <?php echo date('Y'); ?> <?php echo legal_e(legal_entity_name()); ?>.
                <a href="index.php" style="color: #91a8ff;">EduPortal</a>
            </p>
            <nav aria-label="Legal and contact" style="display: flex; gap: 1.25rem; flex-wrap: wrap;">
                <a href="privacy.php" style="color: var(--text-muted); font-size: .85rem;">Privacy Policy</a>
                <a href="terms.php" style="color: var(--text-muted); font-size: .85rem;">Terms of Service</a>
                <a href="contact.php" style="color: var(--text-muted); font-size: .85rem;">Contact</a>
                <?php if ($email !== '') : ?>
                    <a href="mailto:<?php echo legal_e($email); ?>" style="color: var(--text-muted); font-size: .85rem;">Email
                        us</a>
                <?php endif; ?>
                <?php if ($phone !== '') : ?>
                    <a href="tel:<?php echo legal_e(preg_replace('/[^\d+]/', '', $phone) ?? ''); ?>"
                        style="color: var(--text-muted); font-size: .85rem;"><?php echo legal_e($phone); ?></a>
                <?php endif; ?>
            </nav>
        </div>
    </footer>
    <script src="assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="assets/js/pwa.js"></script>
    <?php
}
