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

            const credential = await navigator.credentials.create({
                publicKey: toPublicKey(started.publicKey)
            });

            if (!credential) {
                throw new Error('No passkey was created.');
            }

            return post(endpoints.verify, {
                credential: JSON.stringify(serialise(credential)),
                label: label || ''
            });
        },

        async authenticate({ role, identifier, subject, endpoints }) {
            if (!window.EduPortalWebAuthn.isSupported()) {
                throw new Error('This browser does not support passkeys.');
            }

            const started = await post(endpoints.options, {
                role: role,
                identifier: identifier,
                subject: subject || ''
            });

            const assertion = await navigator.credentials.get({
                publicKey: toPublicKey(started.publicKey)
            });

            if (!assertion) {
                throw new Error('No passkey was returned.');
            }

            return post(endpoints.verify, {
                credential: JSON.stringify(serialise(assertion))
            });
        },

        async revoke({ passkeyId, password, endpoints }) {
            return post(endpoints.revoke, {
                passkey_id: passkeyId,
                password: password
            });
        },

        async rename({ passkeyId, label, endpoints }) {
            return post(endpoints.rename, {
                passkey_id: passkeyId,
                label: label
            });
        }
    };
})();
