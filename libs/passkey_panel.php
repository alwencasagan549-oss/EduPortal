<?php
/**
 * Shared passkey management panel.
 *
 * Rendered on both profile pages so enrolment and revocation have one
 * implementation rather than two that drift.
 *
 * Hides itself entirely when the server-side library or the browser API is
 * unavailable, rather than showing controls that cannot work.
 */

require_once __DIR__ . '/WebAuthnService.php';

/**
 * @param string $base Path prefix back to the application root. Both profile
 *                     pages sit one directory deep, so this is '..'.
 */
function render_passkey_panel(string $base = '..'): void
{
    $serverReady = webauthn_available();
    ?>
    <section class="glass-card" id="passkey-panel"
             data-base="<?php echo htmlspecialchars($base); ?>"
             data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>"
             data-ready="<?php echo $serverReady ? '1' : '0'; ?>"
             style="padding: 1.75rem; margin-top: 1.5rem;">
        <h2 style="margin-bottom: 0.75rem; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-fingerprint" style="color: var(--primary-color)"></i> Passkeys
        </h2>

        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem;">
            A passkey lets you sign in with your fingerprint, face, or device PIN instead of a password.
            The biometric check happens on this device -- EduPortal only ever stores a public key, never
            your fingerprint or face.
        </p>

        <div id="passkey-status" role="status" aria-live="polite" style="margin-bottom: 1rem; display: none;"></div>

        <ul id="passkey-list" style="list-style: none; padding: 0; margin: 0 0 1.25rem; display: flex; flex-direction: column; gap: 0.75rem;"></ul>

        <p id="passkey-empty" style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
            No passkeys registered yet.
        </p>

        <div id="passkey-stepup" hidden
             style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem;">
            <label for="passkey-password" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">
                Confirm your current password to continue
            </label>
            <input type="password" id="passkey-password" class="premium-input" autocomplete="current-password"
                   style="margin-bottom: 1rem;">
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button type="button" id="passkey-confirm" class="premium-btn premium-btn-primary">
                    <i class="fas fa-check"></i> Confirm
                </button>
                <button type="button" id="passkey-cancel" class="premium-btn premium-btn-outline">
                    Cancel
                </button>
            </div>
        </div>

        <button type="button" id="passkey-add" class="premium-btn premium-btn-primary">
            <i class="fas fa-plus"></i> Add a passkey
        </button>
    </section>
    <script src="<?php echo htmlspecialchars($base); ?>/assets/js/webauthn.js?v=<?php echo WEBAUTHN_JS_VERSION; ?>"></script>
    <script>
    (() => {
        const panel = document.getElementById('passkey-panel');
        if (!panel || typeof window.EduPortalWebAuthn === 'undefined') {
            return;
        }

        const api = window.EduPortalWebAuthn;
        const base = panel.dataset.base;

        const endpoints = {
            options: base + '/controllers/webauthn_register_options.php',
            verify: base + '/controllers/webauthn_register_verify.php',
            revoke: base + '/controllers/webauthn_revoke.php',
            rename: base + '/controllers/webauthn_rename.php'
        };

        const list = panel.querySelector('#passkey-list');
        const empty = panel.querySelector('#passkey-empty');
        const status = panel.querySelector('#passkey-status');
        const stepup = panel.querySelector('#passkey-stepup');
        const password = panel.querySelector('#passkey-password');
        const addButton = panel.querySelector('#passkey-add');
        const confirmButton = panel.querySelector('#passkey-confirm');
        const cancelButton = panel.querySelector('#passkey-cancel');

        api.init(panel.dataset.csrf);

        if (panel.dataset.ready !== '1' || !api.isSupported()) {
            panel.style.display = 'none';
            return;
        }

        // Actions are tracked as a closure instead of a module-level variable
        // so a second panel on the same page cannot overwrite the first's.
        let pendingAction = null;

        const say = (message, kind) => {
            status.textContent = message;
            status.style.display = 'block';
            status.className = 'alert alert-' + (kind || 'info');
        };

        const clearStatus = () => {
            status.style.display = 'none';
            status.textContent = '';
        };

        const formatDate = value => {
            if (!value) {
                return 'Never used';
            }
            const parsed = new Date(String(value).replace(' ', 'T') + 'Z');
            return Number.isNaN(parsed.getTime())
                ? String(value)
                : 'Last used ' + parsed.toLocaleDateString();
        };

        const render = passkeys => {
            list.textContent = '';
            empty.style.display = passkeys.length ? 'none' : 'block';

            passkeys.forEach(passkey => {
                const item = document.createElement('li');
                item.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:1rem;'
                    + 'background:rgba(0,0,0,0.2);border-radius:12px;padding:0.9rem 1.1rem;flex-wrap:wrap;';

                const text = document.createElement('div');
                const name = document.createElement('div');
                name.style.cssText = 'font-weight:600;font-size:0.9rem;';
                name.textContent = passkey.label || 'Passkey';

                const meta = document.createElement('div');
                meta.style.cssText = 'color:var(--text-muted);font-size:0.78rem;margin-top:2px;';
                meta.textContent = (passkey.synced ? 'Synced across devices' : 'This device only')
                    + ' · ' + formatDate(passkey.last_used_at);

                text.appendChild(name);
                text.appendChild(meta);

                const actions = document.createElement('div');
                actions.style.cssText = 'display:flex;gap:0.5rem;';

                const rename = document.createElement('button');
                rename.type = 'button';
                rename.className = 'premium-btn premium-btn-outline';
                rename.style.cssText = 'padding:0.5rem 0.9rem;font-size:0.8rem;';
                rename.textContent = 'Rename';
                rename.addEventListener('click', async () => {
                    // prompt() is used deliberately here: it is a single
                    // cosmetic field, and a styled modal for one text input
                    // would be more code than the feature deserves.
                    const next = window.prompt('Name this passkey', passkey.label || 'Passkey');
                    if (next === null) {
                        return;
                    }
                    try {
                        const result = await api.rename({
                            passkeyId: passkey.id,
                            label: next,
                            endpoints
                        });
                        render(result.passkeys || []);
                        say('Passkey renamed.', 'success');
                    } catch (error) {
                        say(error.message || api.explain(error), 'danger');
                    }
                });

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'premium-btn premium-btn-outline';
                remove.style.cssText = 'padding:0.5rem 0.9rem;font-size:0.8rem;';
                remove.textContent = 'Remove';
                remove.addEventListener('click', () => {
                    pendingAction = () => api.revoke({
                        passkeyId: passkey.id,
                        password: password.value,
                        endpoints
                    }).then(result => {
                        render(result.passkeys || []);
                        say(result.remaining === 0
                            ? 'That was your last passkey. You can still sign in with your password.'
                            : 'Passkey removed.', 'success');
                    });
                    openStepUp();
                });

                actions.appendChild(rename);
                actions.appendChild(remove);
                item.appendChild(text);
                item.appendChild(actions);
                list.appendChild(item);
            });
        };

        const openStepUp = () => {
            clearStatus();
            password.value = '';
            stepup.hidden = false;
            addButton.disabled = true;
            password.focus();
        };

        const closeStepUp = () => {
            stepup.hidden = true;
            addButton.disabled = false;
            password.value = '';
            pendingAction = null;
        };

        addButton.addEventListener('click', () => {
            pendingAction = () => api.register({
                password: password.value,
                label: 'Passkey',
                endpoints
            }).then(result => {
                render(result.passkeys || []);
                say('Passkey added. You can now sign in with it.', 'success');
            });
            openStepUp();
        });

        cancelButton.addEventListener('click', closeStepUp);

        confirmButton.addEventListener('click', async () => {
            if (!pendingAction) {
                closeStepUp();
                return;
            }

            const action = pendingAction;
            confirmButton.disabled = true;
            say('Waiting for your device...', 'info');

            try {
                await action();
                closeStepUp();
            } catch (error) {
                say(error.message || api.explain(error), 'danger');
            } finally {
                confirmButton.disabled = false;
            }
        });

        fetch(base + '/controllers/webauthn_passkeys.php', { credentials: 'same-origin' })
            .then(response => response.json())
            .then(payload => {
                if (payload && Array.isArray(payload.passkeys)) {
                    render(payload.passkeys);
                }
            })
            .catch(() => {
                // A failed initial load leaves the empty-state message in
                // place; enrolment still works and re-renders the list.
            });
    })();
    </script>
    <?php
}
