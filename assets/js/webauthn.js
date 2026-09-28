/**
 * WebAuthn passkey client helper.
 *
 * Exposes window.EduPortalWebAuthn with the three operations the portal needs:
 * register, authenticate, and a support probe. No framework, no build step,
 * matching the rest of the front end.
 *
 * ArrayBuffer values in the server's JSON are base64url strings, so every
 * buffer is converted on the way in and out. Getting that wrong is the usual
 * reason a ceremony fails with an unhelpful "invalid state".
 */
(() => {
    if (window.EduPortalWebAuthn) {
        return;
    }

    const decode = value => {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    };

    const encode = buffer => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };

    /**
     * navigator.credentials.get/create need real ArrayBuffers, not the
     * base64url strings the API endpoint returns.
     */
    const toPublicKey = options => ({
        ...options,
        challenge: decode(options.challenge),
        user: options.user
            ? { ...options.user, id: decode(options.user.id) }
            : undefined,
        excludeCredentials: options.excludeCredentials
            ? options.excludeCredentials.map(item => ({ ...item, id: decode(item.id) }))
            : undefined,
        allowCredentials: options.allowCredentials
            ? options.allowCredentials.map(item => ({ ...item, id: decode(item.id) }))
            : undefined
    });

    /**
     * Registration and assertion carry different response members. Branching
     * on which of those members is present is the reliable way to tell them
     * apart -- checking clientExtensionResults, which is optional on both,
     * silently produces an empty response object.
     */
    const serialise = credential => {
        const base = {
            id: credential.id,
            rawId: encode(credential.rawId),
            type: credential.type,
            clientExtensionResults: credential.getClientExtensionResults
                ? credential.getClientExtensionResults()
                : {}
        };

        if (credential.response.attestationObject) {
            return {
                ...base,
                response: {
                    clientDataJSON: encode(credential.response.clientDataJSON),
                    attestationObject: encode(credential.response.attestationObject),
                    transports: credential.response.getTransports
                        ? credential.response.getTransports()
                        : undefined
                }
            };
        }

        return {
            ...base,
            response: {
                clientDataJSON: encode(credential.response.clientDataJSON),
                authenticatorData: encode(credential.response.authenticatorData),
                signature: encode(credential.response.signature),
                userHandle: credential.response.userHandle
                    ? encode(credential.response.userHandle)
                    : null
            }
        };
    };

    /** Turns DOMException names into something a teacher or student can act on. */
    const explain = error => {
        if (!error || !error.name) {
            return 'Passkey request failed.';
        }

        switch (error.name) {
            case 'NotAllowedError':
                return 'Passkey request was cancelled or timed out.';
            case 'InvalidStateError':
                return 'This passkey is already registered on your account.';
            case 'NotSupportedError':
                return 'This device does not support passkeys.';
            case 'SecurityError':
                return 'Passkeys require a secure (HTTPS) connection.';
            case 'AbortError':
                return 'The passkey prompt was dismissed.';
            default:
                return 'Passkey request failed: ' + error.name;
        }
    };

    /**
     * The server rotates the CSRF token on every state-changing request, and
     * only the immediately previous token stays valid. A token baked into the
     * page therefore dies after the second call, so it is refreshed from every
     * response that echoes the new one.
     */
    let csrfToken = '';

    const post = async (url, body) => {
        // An uninitialised token means the caller forgot init(). Posting an
        // empty one produces a bare 403 that reads like a security problem
        // rather than a wiring mistake, which is a waste of everyone's time.
        if (csrfToken === '') {
            throw new Error('Security token was not initialised. Reload the page and try again.');
        }

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({ ...body, csrf_token: csrfToken }).toString()
        });

        let payload = {};
        try {
            payload = await response.json();
        } catch (error) {
            payload = {};
        }

        if (typeof payload.csrf_token === 'string' && payload.csrf_token !== '') {
            csrfToken = payload.csrf_token;
        }

        if (!response.ok) {
            const failure = new Error(payload.error || 'Request failed (' + response.status + ').');
            failure.status = response.status;
            throw failure;
        }

        return payload;
    };

    window.EduPortalWebAuthn = {
        isSupported: () =>
            typeof window.PublicKeyCredential !== 'undefined' &&
            typeof navigator.credentials !== 'undefined',

        init: token => {
            csrfToken = String(token || '');
        },

        explain,

        async register({ password, label, endpoints }) {
            if (!window.EduPortalWebAuthn.isSupported()) {
                throw new Error('This browser does not support passkeys.');
            }

            const started = await post(endpoints.options, { password: password });

            // navigator.credentials.create() reports anything missing from the
            // options as "Required parameters missing in options.publicKey",
            // which names neither the field nor the side responsible. Checking
            // here turns that into a message that points at the response.
            const options = started && started.publicKey;
            const missing = ['challenge', 'rp', 'user'].filter(key => !options || !options[key]);
            if (missing.length > 0) {
                throw new Error(
                    'The server returned incomplete passkey options (missing: ' + missing.join(', ')
                    + '). Open DevTools > Network > webauthn_register_options.php > Response.'
                );
            }

            const publicKey = toPublicKey(options);

            // Chrome's "Required parameters missing in options.publicKey" names
            // neither the field nor the type, and is raised after this code has
            // already reshaped the object. One line of JSON is the only
            // faithful record of what crossed the boundary.
            if (window.console && console.info) {
                console.info('[EduPortal] PASSKEY_OPTIONS_JSON ' + JSON.stringify(options));
                console.info(
                    '[EduPortal] challenge is ArrayBuffer',
                    publicKey.challenge instanceof ArrayBuffer,
                    'bytes',
                    publicKey.challenge && publicKey.challenge.byteLength
                );
            }

            const credential = await navigator.credentials.create({
                publicKey: publicKey
            });
            if (!credential) {
                throw new Error('No passkey was created.');
            }

            return post(endpoints.verify, {
                credential: JSON.stringify(serialise(credential)),
                label: label || ''
            });
        },

        /**
         * Identifier is optional. Supplied, the server scopes the ceremony to
         * that account's credentials, which is more precise and also works for
         * credentials that are not discoverable. Omitted, the authenticator
         * resolves the account from the discoverable credential it already
         * holds, and the user signs in with the passkey alone.
         */
        async authenticate({ role, identifier, subject, endpoints }) {
            if (!window.EduPortalWebAuthn.isSupported()) {
                throw new Error('This browser does not support passkeys.');
            }

            const scoped = Boolean(identifier);

            const started = await post(endpoints.options, {
                role: role,
                identifier: identifier || '',
                subject: subject || ''
            });

            if (!started || !started.publicKey) {
                throw new Error('The server did not return passkey options. Try again.');
            }

            if (scoped && started.scoped === false) {
                throw new Error('The server could not scope this sign-in to that account.');
            }

            const assertion = await navigator.credentials.get({
                publicKey: toPublicKey(started.publicKey)
            });

            if (!assertion) {
                throw new Error('No passkey was returned.');
            }

            return post(endpoints.verify, {
                credential: JSON.stringify(serialise(assertion)),
                // For teachers this chooses which of their subject rows to
                // sign in as. The passkey has already proved who they are; one
                // teacher teaching two subjects is two rows sharing an email
                // and a password, and without this they always land on the one
                // they enrolled from.
                subject: subject || ''
            });
        },

        async revoke({ passkeyId, password, endpoints }) {
            return post(endpoints.revoke, {
                passkey_id: passkeyId,
                password: password
            });
        },

        /**
         * Wires step-up password confirmation plus passkey creation to a
         * container, so the enrolment ceremony is implemented once and shared
         * by the profile panel and the dashboard reminder.
         *
         * Expects these data attributes inside root:
         *   data-passkey-start    button that reveals the step-up
         *   data-passkey-stepup   container revealed for the password
         *   data-passkey-password password input
         *   data-passkey-confirm  button that runs the ceremony (optional)
         *   data-passkey-cancel   button that closes the step-up (optional)
         *   data-passkey-status   live region for feedback
         */
        mountEnroller(root, { endpoints, onDone }) {
            const query = selector => root.querySelector(selector);
            const status = query('[data-passkey-status]');
            const stepup = query('[data-passkey-stepup]');
            const password = query('[data-passkey-password]');
            const start = query('[data-passkey-start]');
            const confirm = query('[data-passkey-confirm]');
            const cancel = query('[data-passkey-cancel]');

            if (!window.EduPortalWebAuthn.isSupported() || !status || !stepup || !password || !start) {
                return null;
            }

            let busy = false;

            const say = (message, kind) => {
                status.textContent = message;
                status.style.display = message ? 'block' : 'none';
                status.className = 'alert alert-' + (kind || 'info');
            };

            const open = () => {
                say('');
                password.value = '';
                stepup.hidden = false;
                start.disabled = true;
                if (typeof password.focus === 'function') {
                    password.focus();
                }
            };

            const close = () => {
                stepup.hidden = true;
                start.disabled = false;
                password.value = '';
            };

            start.addEventListener('click', open);
            if (cancel) {
                cancel.addEventListener('click', close);
            }

            if (confirm) {
                confirm.addEventListener('click', async () => {
                    // Guarded because a double click would otherwise start two
                    // concurrent ceremonies, and the second would fail on a
                    // challenge the first already consumed.
                    if (busy) {
                        return;
                    }
                    busy = true;
                    confirm.disabled = true;
                    say('Waiting for your device...', 'info');

                    try {
                        const result = await window.EduPortalWebAuthn.register({
                            password: password.value,
                            label: '',
                            endpoints: endpoints
                        });
                        close();
                        say('Passkey added. You can sign in with it from now on.', 'success');
                        if (typeof onDone === 'function') {
                            onDone(result);
                        }
                    } catch (error) {
                        say(error.message || window.EduPortalWebAuthn.explain(error), 'danger');
                    } finally {
                        busy = false;
                        confirm.disabled = false;
                    }
                });
            }

            return { open, close, say };
        },

        async rename({ passkeyId, label, endpoints }) {
            return post(endpoints.rename, {
                passkey_id: passkeyId,
                label: label
            });
        }
    };
})();
