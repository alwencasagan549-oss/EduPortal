<?php
/**
 * Passkey enrolment reminder.
 *
 * A dismissible nudge, not a requirement. It is rendered only when the account
 * has no active passkey, and the browser hides it when the user has recently
 * dismissed it or when the browser cannot use passkeys at all.
 *
 * Deliberately NOT a blocking modal. A prompt that cannot be dismissed locks
 * out anyone on a shared or school computer, on a browser without a platform
 * authenticator, or who simply declines. It is also a poor recommendation on
 * a shared device: enrolling a passkey on a machine other people use is worse
 * for the account, not better.
 *
 * Dismissal is stored per account in localStorage rather than in the database,
 * so this needs no migration and works immediately after a deploy. The cost is
 * that the reminder reappears on a new browser, which is arguably correct.
 */

require_once __DIR__ . '/WebAuthnService.php';

/**
 * Days to stay quiet after the user dismisses the reminder.
 */
const PASSKEY_REMINDER_SNOOZE_DAYS = 7;

function render_passkey_reminder(string $base = '..'): void
{
    $conn = getDBConnection();
    $role = getUserRole();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if (!webauthn_available() || $userId <= 0) {
        return;
    }

    // The authoritative check. Once a credential exists the reminder is
    // pointless, and this is decided server-side so a stale localStorage entry
    // cannot keep it on screen.
    if (webauthn_has_passkeys($conn, $role, $userId)) {
        return;
    }
    ?>
    <section id="passkey-reminder" hidden
             data-base="<?php echo htmlspecialchars($base); ?>"
             data-scope="<?php echo htmlspecialchars($role . ':' . $userId); ?>"
             data-snooze-days="<?php echo PASSKEY_REMINDER_SNOOZE_DAYS; ?>"
             aria-labelledby="passkey-reminder-title"
             style="margin-bottom: 1.5rem; padding: 1.25rem 1.5rem; border-radius: 16px;
                    border: 1px solid var(--glass-border);
                    background: linear-gradient(135deg, rgba(78,115,223,0.14) 0%, transparent 70%);">
        <div style="display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
            <i class="fas fa-fingerprint" aria-hidden="true"
               style="font-size: 1.6rem; color: var(--primary-color); margin-top: 2px;"></i>

            <div style="flex: 1; min-width: 240px;">
                <h2 id="passkey-reminder-title" style="font-size: 1rem; margin: 0 0 0.35rem;">
                    Set up a passkey
                </h2>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0 0 0.5rem;">
                    Sign in with your fingerprint, face, or device PIN instead of typing a password.
                    The biometric check happens on this device &mdash; EduPortal only stores a public key,
                    never your fingerprint or face.
                </p>
                <p style="color: var(--text-muted); font-size: 0.78rem; margin: 0;">
                    <strong>Only do this on a device you trust alone.</strong>
                    On a shared computer, skip it &mdash; your password is a safer option there.
                </p>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.5rem; align-items: stretch;">
                <button type="button" data-passkey-start class="premium-btn premium-btn-primary"
                        style="padding: 0.6rem 1.1rem; font-size: 0.85rem; justify-content: center;">
                    <i class="fas fa-plus"></i> Set up now
                </button>
                <button type="button" data-passkey-dismiss
                        style="background: none; border: none; color: var(--text-muted); font-size: 0.78rem; cursor: pointer; text-decoration: underline;">
                    Remind me later
                </button>
            </div>
        </div>

        <div data-passkey-stepup hidden
             style="margin-top: 1rem; background: rgba(0,0,0,0.2); border-radius: 12px; padding: 1.25rem;">
            <label for="passkey-reminder-password"
                   style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">
                Confirm your current password to continue
            </label>
            <input type="password" id="passkey-reminder-password" data-passkey-password
                   autocomplete="current-password" class="premium-input" style="margin-bottom: 1rem;">
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button type="button" data-passkey-confirm class="premium-btn premium-btn-primary">
                    <i class="fas fa-check"></i> Confirm
                </button>
                <button type="button" data-passkey-cancel class="premium-btn premium-btn-outline">
                    Cancel
                </button>
            </div>
        </div>

        <div data-passkey-status role="status" aria-live="polite" style="margin-top: 1rem; display: none;"></div>
    </section>
    <script src="<?php echo htmlspecialchars($base); ?>/assets/js/webauthn.js"></script>
    <script>
    (() => {
        const root = document.getElementById('passkey-reminder');
        if (!root || typeof window.EduPortalWebAuthn === 'undefined') {
            return;
        }

        const api = window.EduPortalWebAuthn;
        if (!api.isSupported()) {
            // No platform authenticator on this browser. Showing a prompt the
            // user cannot act on is worse than showing nothing.
            return;
        }

        const scope = 'eduportal.passkey-reminder.' + root.dataset.scope;
        const snoozeDays = parseInt(root.dataset.snoozeDays, 10) || 7;

        let snoozedUntil = 0;
        try {
            snoozedUntil = parseInt(window.localStorage.getItem(scope) || '0', 10) || 0;
        } catch (error) {
            // Private browsing or storage disabled. Showing the reminder is the
            // safe fallback, so the catch is deliberately empty.
        }

        if (Date.now() < snoozedUntil) {
            return;
        }

        const hide = () => {
            root.hidden = true;
        };

        const snooze = () => {
            try {
                window.localStorage.setItem(scope, String(Date.now() + snoozeDays * 86400000));
            } catch (error) {
                // Nothing to do; the banner simply reappears next login.
            }
            hide();
        };

        root.querySelector('[data-passkey-dismiss]').addEventListener('click', snooze);

        api.mountEnroller(root, {
            endpoints: {
                options: root.dataset.base + '/controllers/webauthn_register_options.php',
                verify: root.dataset.base + '/controllers/webauthn_register_verify.php'
            },
            onDone: () => {
                // Enrolment succeeded, so the reminder has done its job.
                try {
                    window.localStorage.removeItem(scope);
                } catch (error) {
                    // Non-fatal.
                }
                setTimeout(hide, 4000);
            }
        });

        root.hidden = false;
    })();
    </script>
    <?php
}
