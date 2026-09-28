<?php
/**
 * WebAuthn (passkey) service.
 *
 * The portal never sees a biometric sample. The device's platform
 * authenticator (Touch ID, Android fingerprint, Windows Hello) performs the
 * fingerprint or face match locally and returns a cryptographic assertion
 * over a per-ceremony challenge. Everything stored here is public key
 * material and opaque identifiers.
 *
 * The verification flow deliberately reconstructs the ceremony options from
 * server-side state rather than echoing back what was sent to the browser.
 * The client-supplied options are never trusted; they are only used to pull
 * out the challenge, which is then matched against the database.
 *
 * Depends on web-auth/webauthn-lib. If the Composer install is missing the
 * service reports itself unavailable and the UI hides itself, rather than
 * fataling on an unauthenticated page.
 */

require_once __DIR__ . '/AuthService.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Cose\Algorithms;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\EdDSA\EdDSA;
use Cose\Algorithm\Signature\RSA\RS256;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Symfony\Component\Serializer\SerializerInterface;

const WEBAUTHN_CHALLENGE_TTL = 300;
const WEBAUTHN_USER_HANDLE_BYTES = 32;
const WEBAUTHN_CREDENTIAL_LABEL_MAX = 64;

/**
 * Cache-buster for assets/js/webauthn.js.
 *
 * The rest of the front end versions its assets (?v=20260924 on
 * system_loader.js and style.min.css), but this file was loaded bare, so a
 * returning user kept the copy their browser first cached and no change to it
 * ever reached them. Bump this whenever the file's behaviour changes.
 */
const WEBAUTHN_JS_VERSION = '20260928-5';

/**
 * Signature counter policy.
 *
 * The library ships ThrowExceptionIfInvalid as the default, which rejects any
 * assertion whose counter does not exceed the stored value. That is correct
 * for a device-bound credential but breaks every synced passkey: WebAuthn
 * Level 3 section 6.1.3 says a multi-device credential reports a permanently
 * zero counter, and iCloud Keychain and Google Password Manager both do.
 *
 * Enforcing monotonicity unconditionally would therefore lock out the majority
 * of real users on their second sign-in. The counter is only a clone signal
 * when the credential is NOT backed up.
 */
final class EduPortalSignCounterChecker implements CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        // Synced passkey, WebAuthn L3 6.1.3: a multi-device credential
        // reports a permanently zero counter, and iCloud Keychain and Google
        // Password Manager both do.
        if ($credentialRecord->backupStatus === true) {
            return;
        }

        // A zero counter carries no information. It is indistinguishable from
        // an authenticator that simply does not maintain one, and several
        // platform authenticators never increment it. Rejecting on a
        // non-increasing pair of zeros would lock those users out of their own
        // accounts, which is why the library's default checker skips
        // enforcement whenever either side is zero.
        if ($credentialRecord->counter === 0 || $currentCounter === 0) {
            return;
        }

        if ($currentCounter <= $credentialRecord->counter) {
            throw CounterException::create(
                $currentCounter,
                $credentialRecord->counter,
                'Invalid counter.'
            );
        }
    }
}

/**
 * Why the most recent registration or authentication attempt could not start.
 *
 * These ceremonies have several early returns -- missing library, unresolvable
 * Relying Party ID, no user handle, unissued challenge -- and returning a bare
 * null turned every one of them into the same "passkeys could not be started"
 * with nothing recorded anywhere. Kept in the same shape as the mail
 * diagnostics so the reason lands in the audit trail, not only in a log file.
 */
function webauthn_last_error(): string
{
    return $GLOBALS['webauthn_error'] ?? '';
}

function webauthn_record_error(string $message): void
{
    $GLOBALS['webauthn_error'] = substr(trim($message), 0, 300);
}

// ---------------------------------------------------------------------
// Availability and configuration
// ---------------------------------------------------------------------

function webauthn_available(): bool
{
    return class_exists(WebauthnSerializerFactory::class)
        && class_exists(AuthenticatorAttestationResponseValidator::class);
}

/**
 * The Relying Party ID. Defaults to the host of SITE_URL.
 *
 * This value is effectively permanent: a passkey is scoped to the RP ID that
 * created it, so changing the domain later silently invalidates every
 * enrolled credential. Override with WEBAUTHN_RP_ID only if the portal is
 * served from a different host than SITE_URL advertises.
 */
function webauthn_rp_id(): ?string
{
    $configured = trim((string) (getenv('WEBAUTHN_RP_ID') ?: ''));
    if ($configured !== '') {
        return $configured;
    }

    if (!defined('SITE_URL')) {
        return null;
    }

    $host = parse_url((string) SITE_URL, PHP_URL_HOST);

    return is_string($host) && $host !== '' ? $host : null;
}

/**
 * Exact origins accepted in clientDataJSON. Defaults to the origin of
 * SITE_URL. Comma-separated via WEBAUTHN_ORIGINS when the portal answers on
 * more than one host.
 */
function webauthn_origins(): array
{
    $configured = trim((string) (getenv('WEBAUTHN_ORIGINS') ?: ''));
    if ($configured !== '') {
        $origins = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if ($origins !== []) {
            return $origins;
        }
    }

    if (!defined('SITE_URL')) {
        return [];
    }

    $parts = parse_url((string) SITE_URL);
    if (!is_array($parts) || empty($parts['host'])) {
        return [];
    }

    $scheme = $parts['scheme'] ?? 'https';
    $origin = $scheme . '://' . $parts['host'];

    if (isset($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    return [$origin];
}

function webauthn_rp_name(): string
{
    return defined('PLATFORM_NAME') ? (string) PLATFORM_NAME : 'EduPortal LMS';
}

function webauthn_base64url(string $binary): string
{
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function webauthn_base64url_decode(string $value): string
{
    $padded = strtr($value, '-_', '+/');
    $remainder = strlen($padded) % 4;
    if ($remainder !== 0) {
        $padded .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode($padded, true);

    return $decoded === false ? '' : $decoded;
}

// ---------------------------------------------------------------------
// Wiring
// ---------------------------------------------------------------------

function webauthn_serializer(): ?SerializerInterface
{
    static $serializer = null;
    static $built = false;

    if ($built) {
        return $serializer;
    }
    $built = true;

    if (!webauthn_available()) {
        return null;
    }

    try {
        $serializer = (new WebauthnSerializerFactory(webauthn_attestation_manager()))->create();
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn serializer unavailable: ' . $exception->getMessage());
        $serializer = null;
    }

    return $serializer;
}

function webauthn_attestation_manager(): AttestationStatementSupportManager
{
    // 'none' only. The portal asks for attestation: 'none', so platform
    // authenticators return fmt "none" and no trust anchor is needed. Adding
    // the full attestation chain here without a metadata service would create
    // the illusion of device attestation that does not actually happen.
    return new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
}

/**
 * Whether EdDSA (-8) can be used.
 *
 * The library checks this at construction time and throws when the sodium
 * extension is absent, so the algorithm is only registered when usable. A
 * device that negotiates -8 against a server without it gets a registration
 * failure that looks like a client problem, so the absence is logged once
 * rather than left to be diagnosed from a user's report.
 *
 * ES256 and RS256 cover the overwhelming majority of real authenticators, so
 * this degrades capability rather than breaking the feature.
 */
function webauthn_eddsa_available(): bool
{
    static $warned = false;

    if (!class_exists(EdDSA::class) || !EdDSA::isSupported()) {
        if (!$warned) {
            $warned = true;
            error_log('EduPortal WebAuthn: the sodium extension is unavailable, so EdDSA (-8) passkeys are disabled. ES256 and RS256 remain available.');
        }
        return false;
    }

    return true;
}

function webauthn_ceremony_factory(): CeremonyStepManagerFactory
{
    $factory = new CeremonyStepManagerFactory();

    $origins = webauthn_origins();
    if ($origins !== []) {
        // Exact scheme+host+port matching. This is stricter than the library's
        // legacy suffix check, and rejects subdomain and scheme confusion.
        $factory->setAllowedOrigins($origins, false);
    }

    $factory->setCounterChecker(new EduPortalSignCounterChecker());

    $algorithms = CoseAlgorithmManager::create()->add(ES256::create(), RS256::create());
    if (webauthn_eddsa_available()) {
        $algorithms = $algorithms->add(new EdDSA());
    }
    $factory->setAlgorithmManager($algorithms);

    return $factory;
}

function webauthn_attestation_validator(): ?AuthenticatorAttestationResponseValidator
{
    return webauthn_available() ? AuthenticatorAttestationResponseValidator::create(webauthn_ceremony_factory()->creationCeremony()) : null;
}

function webauthn_assertion_validator(): ?AuthenticatorAssertionResponseValidator
{
    return webauthn_available() ? AuthenticatorAssertionResponseValidator::create(webauthn_ceremony_factory()->requestCeremony()) : null;
}

// ---------------------------------------------------------------------
// Stable opaque user handle
// ---------------------------------------------------------------------

/**
 * Returns the account's opaque WebAuthn user handle, allocating one on first
 * use and persisting it so the same value is reused for every credential.
 *
 * The handle must be opaque random bytes. Using students.id or the LRN would
 * disclose a real identifier to every relying party the passkey is presented
 * to, and would change meaning if the primary key were ever renumbered.
 */
function webauthn_user_handle($conn, string $role, $userId): ?string
{
    $table = auth_role_table($role);
    if ($table === null || !auth_column_exists($conn, $table, 'passkey_user_handle')) {
        return null;
    }

    $userId = (int) $userId;
    if ($userId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare("SELECT passkey_user_handle FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        $existing = $stmt->get_result()->fetchColumn();
    } catch (Throwable $exception) {
        error_log('EduPortal user handle read failed: ' . $exception->getMessage());
        return null;
    }

    if (is_string($existing) && $existing !== '') {
        return webauthn_base64url_decode($existing);
    }

    $handle = random_bytes(WEBAUTHN_USER_HANDLE_BYTES);
    $encoded = webauthn_base64url($handle);

    try {
        // Conditional update: two concurrent first-time registrations both
        // generate a handle, and only one may win the column.
        $update = $conn->prepare(
            "UPDATE {$table} SET passkey_user_handle = ? WHERE id = ? AND (passkey_user_handle IS NULL OR passkey_user_handle = '')"
        );
        $update->execute([$encoded, $userId]);

        if ($update->rowCount() === 1) {
            return $handle;
        }

        $stmt = $conn->prepare("SELECT passkey_user_handle FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        $winner = $stmt->get_result()->fetchColumn();

        return is_string($winner) && $winner !== '' ? webauthn_base64url_decode($winner) : null;
    } catch (Throwable $exception) {
        error_log('EduPortal user handle write failed: ' . $exception->getMessage());
        return null;
    }
}

// ---------------------------------------------------------------------
// One-time ceremony challenges
// ---------------------------------------------------------------------

/**
 * Issues a single-use challenge. Returns the raw bytes; the caller hands them
 * to the ceremony options, which base64url-encode them for the browser.
 *
 * Stored in the clear rather than hashed: the library compares the challenge
 * byte-for-byte against clientDataJSON, so the server needs the value itself.
 * A leaked row is not useful because the challenge expires in minutes and is
 * consumed on first use.
 */
function webauthn_issue_challenge($conn, string $purpose, ?string $role, ?int $userId): ?string
{
    if (!auth_table_exists($conn, 'webauthn_challenges')) {
        webauthn_record_error('the webauthn_challenges table is not present in the database');
        error_log('EduPortal WebAuthn challenge blocked: ' . webauthn_last_error());
        return null;
    }

    $challenge = random_bytes(32);
    $now = time();

    // Housekeeping only. Expired rows are already rejected on read, so a
    // failure here must not be able to block issuing a new challenge -- which
    // is what happened when this shared a try block with the insert.
    try {
        $conn->exec('DELETE FROM webauthn_challenges WHERE expires_at < ' . gmdate('Y-m-d H:i:s', $now - 3600));
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn challenge prune skipped: ' . $exception->getMessage());
    }

    try {
        $stmt = $conn->prepare(
            'INSERT INTO webauthn_challenges
                (challenge, purpose, user_role, user_id, request_ip, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            webauthn_base64url($challenge),
            substr($purpose, 0, 16),
            $role,
            $userId,
            auth_client_ip() ?: null,
            gmdate('Y-m-d H:i:s', $now),
            gmdate('Y-m-d H:i:s', $now + WEBAUTHN_CHALLENGE_TTL),
        ]);
    } catch (Throwable $exception) {
        // The insert failing is a different problem from the table being
        // absent, and reporting them as one thing sent the diagnosis nowhere.
        error_log('EduPortal WebAuthn challenge issue failed: ' . $exception->getMessage());
        webauthn_record_error('the challenge could not be stored: ' . $exception->getMessage());
        return null;
    }

    return $challenge;
}

/**
 * Consumes a challenge. The row must exist, be unused, be unexpired, and match
 * the purpose -- and for registration it must belong to the same account that
 * is enrolling, otherwise a challenge issued to one user could be replayed to
 * bind a credential to a different one.
 */
function webauthn_consume_challenge($conn, string $purpose, string $challengeB64, ?string $role = null, ?int $userId = null): bool
{
    if ($challengeB64 === '' || !auth_table_exists($conn, 'webauthn_challenges')) {
        return false;
    }

    $now = time();

    try {
        $stmt = $conn->prepare(
            'SELECT id, challenge FROM webauthn_challenges
             WHERE challenge = ? AND purpose = ? AND consumed_at IS NULL'
        );
        $stmt->execute([$challengeB64, substr($purpose, 0, 16)]);
        $row = $stmt->get_result()->fetch_assoc();

        if (!is_array($row) || !hash_equals((string) $row['challenge'], $challengeB64)) {
            return false;
        }

        $expiresStmt = $conn->prepare('SELECT expires_at FROM webauthn_challenges WHERE id = ?');
        $expiresStmt->execute([(int) $row['id']]);
        $expiresAt = auth_parse_timestamp($expiresStmt->get_result()->fetchColumn());
        if ($expiresAt === null || $expiresAt <= $now) {
            return false;
        }

        if ($role !== null || $userId !== null) {
            $bindStmt = $conn->prepare('SELECT user_role, user_id FROM webauthn_challenges WHERE id = ?');
            $bindStmt->execute([(int) $row['id']]);
            $bound = $bindStmt->get_result()->fetch_assoc();

            if (!is_array($bound)) {
                return false;
            }
            if ($role !== null && (string) ($bound['user_role'] ?? '') !== $role) {
                return false;
            }
            if ($userId !== null && (int) ($bound['user_id'] ?? 0) !== (int) $userId) {
                return false;
            }
        }

        $consume = $conn->prepare('UPDATE webauthn_challenges SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
        $consume->execute([gmdate('Y-m-d H:i:s', $now), (int) $row['id']]);

        return $consume->rowCount() === 1;
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn challenge consume failed: ' . $exception->getMessage());
        return false;
    }
}

/**
 * Pulls the challenge back out of the client response so the server can look
 * it up. Returns null when the payload is not shaped like clientDataJSON.
 */
function webauthn_extract_challenge(array $credential): ?array
{
    $clientDataJson = $credential['response']['clientDataJSON'] ?? null;
    if (!is_string($clientDataJson) || $clientDataJson === '') {
        return null;
    }

    $decoded = json_decode(webauthn_base64url_decode($clientDataJson), true);
    if (!is_array($decoded) || !isset($decoded['challenge']) || !is_string($decoded['challenge'])) {
        return null;
    }

    return [
        'raw' => webauthn_base64url_decode($decoded['challenge']),
        'encoded' => $decoded['challenge'],
        'type' => $decoded['type'] ?? null,
    ];
}

// ---------------------------------------------------------------------
// Ceremony options
//
// Extracted from the begin/finish flows so the creation options are defined
// exactly once. The registration ceremony used to build them twice, and a
// mismatch between what was offered and what was verified would be invisible:
// the challenge check would still pass while the user handle baked into the
// stored credential silently differed from the one later asserted against.
// ---------------------------------------------------------------------

/**
 * @param string $handle    Raw opaque user handle bytes
 * @param string $challenge Raw challenge bytes (not base64url)
 */
function webauthn_creation_options(
    string $rpId,
    string $handle,
    string $challenge,
    string $displayName,
    array $excluded = []
): PublicKeyCredentialCreationOptions {
    return PublicKeyCredentialCreationOptions::create(
        new PublicKeyCredentialRpEntity(webauthn_rp_name(), $rpId),
        new PublicKeyCredentialUserEntity('eduportal', $handle, $displayName),
        $challenge,
        [
            // createPk() rather than create(): the latter takes (type, alg), so
            // passing the algorithm first silently coerces -7 to the string
            // "-7" and every descriptor ships an invalid type. Chrome then
            // rejects the whole options object as "Required parameters missing
            // in options.publicKey", which names neither the cause nor the
            // side responsible.
            PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
            PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_EDDSA),
        ],
        // residentKey required makes the credential discoverable, so the same
        // passkey works on any device the user signs in from.
        // userVerification required is what makes this a *biometric* login
        // rather than mere device possession: the library rejects any
        // assertion without the UV flag.
        AuthenticatorSelectionCriteria::create(
            null,
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
        ),
        'none',
        $excluded,
        WEBAUTHN_CHALLENGE_TTL * 1000
    );
}

/**
 * @param string $challenge Raw challenge bytes (not base64url)
 */
function webauthn_request_options(string $rpId, string $challenge, array $descriptors = []): PublicKeyCredentialRequestOptions
{
    return PublicKeyCredentialRequestOptions::create(
        $challenge,
        $rpId,
        $descriptors,
        AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        WEBAUTHN_CHALLENGE_TTL * 1000
    );
}

// ---------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------

function webauthn_begin_registration($conn, string $role, $userId, array $account): ?array
{
    $serializer = webauthn_serializer();
    $rpId = webauthn_rp_id();
    if ($serializer === null || $rpId === null) {
        webauthn_record_error('registration cannot start: ' . (
            $serializer === null
                ? 'the webauthn serializer is unavailable, so vendor/ may be missing or the autoloader is not loaded'
                : 'SITE_URL or WEBAUTHN_RP_ID did not yield a Relying Party ID'
        ));
        error_log('EduPortal WebAuthn registration blocked: ' . webauthn_last_error());
        return null;
    }

    $handle = webauthn_user_handle($conn, $role, $userId);
    if ($handle === null) {
        webauthn_record_error('registration cannot start: no user handle for this account; the passkey_user_handle column may be missing');
        error_log('EduPortal WebAuthn registration blocked: ' . webauthn_last_error());
        return null;
    }

    $challenge = webauthn_issue_challenge($conn, 'register', $role, (int) $userId);
    if ($challenge === null) {
        error_log('EduPortal WebAuthn registration blocked: ' . webauthn_last_error());
        return null;
    }

    $displayName = trim((string) ($account['name'] ?? ''));
    if ($displayName === '') {
        $displayName = 'EduPortal ' . ucfirst($role);
    }

    try {
        $options = webauthn_creation_options(
            $rpId,
            $handle,
            $challenge,
            $displayName,
            webauthn_excluded_credentials($conn, $role, $userId)
        );

        $serialised = json_decode($serializer->serialize($options, 'json'), true);

        // The library emits authenticatorAttachment: null when no preference is
        // expressed, and Chrome logs "Ignoring unknown
        // publicKey.authenticatorSelection.authenticatorAttachment value" for
        // it. The key has to be absent rather than null, not merely harmless.
        if (is_array($serialised) && isset($serialised['authenticatorSelection'])
            && is_array($serialised['authenticatorSelection'])) {
            $serialised['authenticatorSelection'] = array_filter(
                $serialised['authenticatorSelection'],
                static fn ($value) => $value !== null
            );
        }

        return ['options' => json_encode($serialised)];
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn registration start failed: ' . $exception->getMessage());
        webauthn_record_error('registration could not be prepared: ' . $exception->getMessage());
        return null;
    }
}

/**
 * Already-registered credentials are excluded so the authenticator refuses to
 * create a duplicate for the same device instead of silently making a second.
 */
function webauthn_excluded_credentials($conn, string $role, $userId): array
{
    $descriptors = webauthn_passkey_descriptors($conn, $role, $userId);

    return array_map(static fn (array $row): PublicKeyCredentialDescriptor => $row['descriptor'], $descriptors);
}

/**
 * Verifies an attestation and stores the credential.
 *
 * @return array{ok: bool, error: string, passkey_id: int}
 */
function webauthn_finish_registration($conn, string $role, $userId, string $clientJson, string $label = ''): array
{
    $failure = ['ok' => false, 'error' => 'Passkey registration could not be completed.', 'passkey_id' => 0];

    $serializer = webauthn_serializer();
    $validator = webauthn_attestation_validator();
    $rpId = webauthn_rp_id();
    if ($serializer === null || $validator === null || $rpId === null) {
        return $failure;
    }

    $credential = json_decode($clientJson, true);
    if (!is_array($credential)) {
        return $failure;
    }

    $challenge = webauthn_extract_challenge($credential);
    if ($challenge === null || $challenge['raw'] === '') {
        return $failure;
    }

    // Bound to this account: a challenge issued to somebody else is rejected.
    if (!webauthn_consume_challenge($conn, 'register', $challenge['encoded'], $role, (int) $userId)) {
        return ['ok' => false, 'error' => 'This registration request expired. Please try again.', 'passkey_id' => 0];
    }

    try {
        $publicKeyCredential = $serializer->deserialize($clientJson, PublicKeyCredential::class, 'json');
    } catch (Throwable $exception) {
        return $failure;
    }

    $response = $publicKeyCredential->response;
    if (!$response instanceof AuthenticatorAttestationResponse) {
        return $failure;
    }

    $handle = webauthn_user_handle($conn, $role, $userId);
    if ($handle === null) {
        return $failure;
    }

    // Rebuilt from server state, never echoed back from the browser.
    try {
        $options = webauthn_creation_options($rpId, $handle, $challenge['raw'], 'EduPortal ' . ucfirst($role));
        $record = $validator->check($response, $options, $rpId);
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn attestation rejected: ' . $exception->getMessage());
        return $failure;
    }

    // Defence in depth: the library already enforces UV because the options
    // demand it, but the flag is the entire security claim of this feature, so
    // it is asserted explicitly rather than transitively.
    if (!$response->attestationObject->authData->isUserVerified()) {
        error_log('EduPortal WebAuthn attestation without UV flag');
        return ['ok' => false, 'error' => 'This device could not verify your identity. Try a device with a fingerprint or face unlock.', 'passkey_id' => 0];
    }

    if (!$response->attestationObject->authData->isUserPresent()) {
        return $failure;
    }

    $label = trim($label);
    if ($label === '') {
        $label = 'Passkey';
    }
    $label = substr($label, 0, WEBAUTHN_CREDENTIAL_LABEL_MAX);

    return webauthn_store_passkey($conn, $role, $userId, $record, $label);
}

function webauthn_store_passkey($conn, string $role, $userId, CredentialRecord $record, string $label): array
{
    $serializer = webauthn_serializer();
    if ($serializer === null) {
        return ['ok' => false, 'error' => 'Passkey support is unavailable.', 'passkey_id' => 0];
    }

    $credentialId = webauthn_base64url($record->publicKeyCredentialId);

    try {
        $encoded = $serializer->serialize($record, 'json');
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn credential serialize failed: ' . $exception->getMessage());
        return ['ok' => false, 'error' => 'Passkey could not be saved.', 'passkey_id' => 0];
    }

    try {
        $stmt = $conn->prepare(
            'INSERT INTO passkeys
                (user_role, user_id, user_handle, credential_id, credential_record, aaguid,
                 transports, sign_count, backup_eligible, backup_status, label, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $role,
            (int) $userId,
            webauthn_base64url($record->userHandle),
            $credentialId,
            $encoded,
            $record->aaguid->toRfc4122(),
            implode(',', $record->transports),
            $record->counter,
            $record->backupEligible ? 1 : 0,
            $record->backupStatus ? 1 : 0,
            $label,
            gmdate('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $exception) {
        // A duplicate credential_id is a re-enrolment of the same device, not
        // a new credential. Report it as such rather than as a server fault.
        error_log('EduPortal WebAuthn credential store failed: ' . $exception->getMessage());

        return ['ok' => false, 'error' => 'This passkey is already registered on your account.', 'passkey_id' => 0];
    }

    $idStmt = $conn->prepare('SELECT id FROM passkeys WHERE credential_id = ? AND user_role = ? AND user_id = ?');
    $idStmt->execute([$credentialId, $role, (int) $userId]);
    $passkeyId = (int) ($idStmt->get_result()->fetchColumn() ?: 0);

    if ($passkeyId === 0) {
        // The INSERT above succeeded, so the row exists; this only recovers the
        // id when the lookup missed it. EduPortalDB exposes no lastInsertId(),
        // and PDO's PostgreSQL driver cannot infer the sequence from an INSERT,
        // so the sequence is named explicitly. Guarded because a miss here must
        // not replace a successful enrolment with a fatal undefined-method call.
        try {
            $passkeyId = (int) $conn->getPDO()->lastInsertId('passkeys_id_seq');
        } catch (Throwable $exception) {
            error_log('EduPortal WebAuthn passkey id recovery failed: ' . $exception->getMessage());
        }
    }

    return ['ok' => true, 'error' => '', 'passkey_id' => $passkeyId];
}

// ---------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------

function webauthn_passkey_descriptors($conn, string $role, $userId): array
{
    if (!auth_table_exists($conn, 'passkeys')) {
        return [];
    }

    try {
        $stmt = $conn->prepare(
            'SELECT id, credential_id, transports, sign_count, backup_eligible, backup_status
             FROM passkeys
             WHERE user_role = ? AND user_id = ? AND revoked_at IS NULL
             ORDER BY created_at ASC'
        );
        $stmt->execute([$role, (int) $userId]);
        $result = $stmt->get_result();
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn descriptor read failed: ' . $exception->getMessage());
        return [];
    }

    $descriptors = [];
    while (($row = $result->fetch_assoc()) !== false) {
        $rawId = webauthn_base64url_decode((string) $row['credential_id']);
        if ($rawId === '') {
            continue;
        }

        $transports = array_values(array_filter(array_map('trim', explode(',', (string) ($row['transports'] ?? '')))));
        $descriptor = PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $rawId,
            $transports
        );

        $descriptors[] = [
            'id' => (int) $row['id'],
            'descriptor' => $descriptor,
            'transports' => $transports,
        ];
    }

    return $descriptors;
}

function webauthn_has_passkeys($conn, string $role, $userId): bool
{
    return webauthn_passkey_descriptors($conn, $role, $userId) !== [];
}

/**
 * Starts an authentication ceremony.
 *
 * With an account, the options are scoped to that account's credentials, which
 * is more precise and also works for credentials that are not discoverable.
 *
 * With a null account the options carry no allowCredentials, and the platform
 * authenticator resolves the account from the discoverable credential it holds.
 * Enrolment requires residentKey, so every passkey issued here is discoverable
 * and this path is available to every user -- the user handle in the assertion
 * identifies the account, and verification checks it against the stored one.
 *
 * @param array|null $account null to authenticate without an identifier
 * @return array{options: string, passkey_ids: int[], scoped: bool}|null
 */
function webauthn_begin_authentication($conn, ?string $role, ?array $account): ?array
{
    $serializer = webauthn_serializer();
    $rpId = webauthn_rp_id();
    if ($serializer === null || $rpId === null) {
        webauthn_record_error('authentication cannot start: ' . (
            $serializer === null
                ? 'the webauthn serializer is unavailable, so vendor/ may be missing or the autoloader is not loaded'
                : 'SITE_URL or WEBAUTHN_RP_ID did not yield a Relying Party ID'
        ));
        error_log('EduPortal WebAuthn authentication blocked: ' . webauthn_last_error());
        return null;
    }

    $userId = is_array($account) ? (int) ($account['id'] ?? 0) : 0;
    $scoped = $userId > 0 && $role !== null;

    $descriptors = $scoped ? webauthn_passkey_descriptors($conn, $role, $userId) : [];

    if ($scoped && $descriptors === []) {
        // Confirming that a known account has no passkey would leak that the
        // account exists, so this reports the same generic outcome the
        // discoverable path gives when no credential is presented.
        webauthn_record_error('no passkey is registered for this account');
        return null;
    }

    // Deliberately not bound to an account: in the discoverable path nothing
    // has been proved yet. Binding happens at verification, against the
    // credential that is actually presented.
    $challenge = webauthn_issue_challenge($conn, 'assert', $scoped ? $role : null, $scoped ? $userId : null);
    if ($challenge === null) {
        error_log('EduPortal WebAuthn authentication blocked: ' . webauthn_last_error());
        return null;
    }

    try {
        $options = webauthn_request_options(
            $rpId,
            $challenge,
            array_map(static fn (array $row): PublicKeyCredentialDescriptor => $row['descriptor'], $descriptors)
        );

        return [
            'options' => $serializer->serialize($options, 'json'),
            'passkey_ids' => array_map(static fn (array $row): int => $row['id'], $descriptors),
            'scoped' => $scoped,
        ];
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn authentication start failed: ' . $exception->getMessage());
        webauthn_record_error('authentication could not be prepared: ' . $exception->getMessage());
        return null;
    }
}

/**
 * Verifies an assertion and resolves the owning account.
 *
 * @return array{ok: bool, error: string, user_role: string, user_id: int, account: ?array, sign_count: int}
 */
/**
 * @param string $subject For teachers, chooses which of their subject rows to
 *                       sign in as. A teacher who teaches two subjects has two
 *                       rows sharing an email and a password; the passkey
 *                       proves who they are, the subject picks which of their
 *                       roles to enter. Empty keeps the enrolled row.
 */
function webauthn_finish_authentication($conn, string $clientJson, string $subject = ''): array
{
    $failure = [
        'ok' => false,
        'error' => 'Passkey sign-in could not be completed.',
        'user_role' => '',
        'user_id' => 0,
        'account' => null,
        'sign_count' => 0,
    ];

    $serializer = webauthn_serializer();
    $validator = webauthn_assertion_validator();
    $rpId = webauthn_rp_id();
    if ($serializer === null || $validator === null || $rpId === null) {
        return $failure;
    }

    $credential = json_decode($clientJson, true);
    if (!is_array($credential)) {
        return $failure;
    }

    $challenge = webauthn_extract_challenge($credential);
    if ($challenge === null || $challenge['raw'] === '') {
        return $failure;
    }

    $rawId = webauthn_base64url_decode((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
    if ($rawId === '') {
        return $failure;
    }

    $row = webauthn_load_passkey_by_credential($conn, $rawId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'This passkey is not registered on any EduPortal account.', 'user_role' => '', 'user_id' => 0, 'account' => null, 'sign_count' => 0];
    }

    $role = (string) $row['user_role'];
    $userId = (int) $row['user_id'];
    $expectedHandle = webauthn_base64url_decode((string) $row['user_handle']);

    // The challenge is only now bound to the account that owns the presented
    // credential, so a challenge cannot be harvested from one session and
    // replayed against another.
    if (!webauthn_consume_challenge($conn, 'assert', $challenge['encoded'])) {
        return ['ok' => false, 'error' => 'This sign-in request expired. Please try again.', 'user_role' => $role, 'user_id' => $userId, 'account' => null, 'sign_count' => 0];
    }

    try {
        $publicKeyCredential = $serializer->deserialize($clientJson, PublicKeyCredential::class, 'json');
        $response = $publicKeyCredential->response;

        if (!$response instanceof AuthenticatorAssertionResponse) {
            return $failure;
        }

        $record = $serializer->deserialize((string) $row['credential_record'], CredentialRecord::class, 'json');

        $options = webauthn_request_options(
            $rpId,
            $challenge['raw'],
            [PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $rawId,
                array_values(array_filter(array_map('trim', explode(',', (string) ($row['transports'] ?? '')))))
            )]
        );

        // The final argument is the user handle the response MUST match. This
        // is the check that stops a valid assertion from one account being
        // accepted as proof for a different one.
        $verified = $validator->check($record, $response, $options, $rpId, $expectedHandle);
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn assertion rejected: ' . $exception->getMessage());

        return [
            'ok' => false,
            'error' => 'Passkey sign-in failed. Try again, or sign in with your password.',
            'user_role' => $role,
            'user_id' => $userId,
            'account' => null,
            'sign_count' => 0,
        ];
    }

    if (!$response->authenticatorData->isUserVerified()) {
        return [
            'ok' => false,
            'error' => 'This device could not verify your identity. Sign in with your password instead.',
            'user_role' => $role,
            'user_id' => $userId,
            'account' => null,
            'sign_count' => 0,
        ];
    }

    $account = auth_account_for_role($conn, $role, $userId);
    if ($account === null) {
        return [
            'ok' => false,
            'error' => 'The account for this passkey no longer exists.',
            'user_role' => $role,
            'user_id' => $userId,
            'account' => null,
            'sign_count' => 0,
        ];
    }

    // Teacher approval is re-checked here, not just at password login: a
    // suspended teacher must not be able to keep signing in with a passkey
    // that was enrolled while they were still approved.
    if ($role === 'teacher') {
        require_once __DIR__ . '/teacher_account.php';
    }

    if ($role === 'teacher' && teacher_approval_required()) {
        $status = teacher_account_status($conn, $userId);
        if ($status !== 'approved') {
            return [
                'ok' => false,
                'error' => teacher_account_status_message($status),
                'user_role' => $role,
                'user_id' => $userId,
                'account' => null,
                'sign_count' => 0,
            ];
        }

        // A teacher who teaches several subjects has one row per subject, all
        // sharing an email and a password. The passkey has already proved who
        // they are, so the subject is only used to pick which of their own
        // roles to sign in as. Without this they would land on whichever row
        // they happened to enrol from.
        $subject = trim($subject);
        if ($subject !== '') {
            $selected = auth_find_teacher($conn, (string) $account['email'], $subject);
            if ($selected === null) {
                return [
                    'ok' => false,
                    'error' => 'You do not have an account for that subject. Check the spelling, or sign in with your password.',
                    'user_role' => $role,
                    'user_id' => $userId,
                    'account' => null,
                    'sign_count' => 0,
                ];
            }

            // A different subject row is a different account, so its approval
            // state is checked independently of the enrolled row's.
            $selectedStatus = teacher_account_status($conn, (int) $selected['id']);
            if ($selectedStatus !== 'approved') {
                return [
                    'ok' => false,
                    'error' => teacher_account_status_message($selectedStatus),
                    'user_role' => $role,
                    'user_id' => (int) $selected['id'],
                    'account' => null,
                    'sign_count' => 0,
                ];
            }

            $account = $selected;
            $userId = (int) $selected['id'];
        }
    }

    webauthn_touch_passkey($conn, (int) $row['id'], $verified);

    return [
        'ok' => true,
        'error' => '',
        'user_role' => $role,
        'user_id' => $userId,
        'account' => $account,
        'sign_count' => $verified->counter,
    ];
}

function webauthn_load_passkey_by_credential($conn, string $rawCredentialId): ?array
{
    if (!auth_table_exists($conn, 'passkeys')) {
        return null;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT id, user_role, user_id, user_handle, credential_record, transports
             FROM passkeys WHERE credential_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([webauthn_base64url($rawCredentialId)]);
        $row = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn credential read failed: ' . $exception->getMessage());
        return null;
    }

    return is_array($row) ? $row : null;
}

/**
 * Persists the counter and backup flags the validator just updated, so the
 * next assertion is compared against current state.
 */
function webauthn_touch_passkey($conn, int $passkeyId, CredentialRecord $record): void
{
    try {
        $stmt = $conn->prepare(
            'UPDATE passkeys SET sign_count = ?, backup_eligible = ?, backup_status = ?, last_used_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $record->counter,
            $record->backupEligible ? 1 : 0,
            $record->backupStatus ? 1 : 0,
            gmdate('Y-m-d H:i:s'),
            $passkeyId,
        ]);
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn credential update failed: ' . $exception->getMessage());
    }
}

// ---------------------------------------------------------------------
// Management
// ---------------------------------------------------------------------

function webauthn_list_passkeys($conn, string $role, $userId): array
{
    if (!auth_table_exists($conn, 'passkeys')) {
        return [];
    }

    try {
        $stmt = $conn->prepare(
            'SELECT id, label, aaguid, transports, backup_eligible, backup_status, created_at, last_used_at
             FROM passkeys
             WHERE user_role = ? AND user_id = ? AND revoked_at IS NULL
             ORDER BY created_at ASC'
        );
        $stmt->execute([$role, (int) $userId]);
        $result = $stmt->get_result();
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn list failed: ' . $exception->getMessage());
        return [];
    }

    $passkeys = [];
    while (($row = $result->fetch_assoc()) !== false) {
        $transports = array_values(array_filter(array_map('trim', explode(',', (string) ($row['transports'] ?? '')))));
        $backup = (int) ($row['backup_status'] ?? 0) === 1;

        $passkeys[] = [
            'id' => (int) $row['id'],
            'label' => (string) ($row['label'] ?? 'Passkey'),
            'aaguid' => (string) ($row['aaguid'] ?? ''),
            'transports' => $transports,
            'synced' => $backup,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'last_used_at' => $row['last_used_at'] ?? null,
        ];
    }

    return $passkeys;
}

/**
 * Revokes every active passkey on an account.
 *
 * Called on password reset, not on password change. A reset means the owner
 * either lost access or believes the account was compromised, and in the
 * second case an attacker's passkey would otherwise survive the reset
 * untouched -- which makes the recovery flow a complete bypass. A deliberate
 * password *change* from a signed-in session does not go through here: the
 * owner is present and did not ask to lose their other credential.
 *
 * Soft delete, so the audit trail of when each credential existed survives.
 */
function webauthn_revoke_all_passkeys($conn, string $role, $userId, string $reason = ''): int
{
    if (!auth_table_exists($conn, 'passkeys')) {
        return 0;
    }

    try {
        $stmt = $conn->prepare(
            'UPDATE passkeys SET revoked_at = ?
             WHERE user_role = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $role, (int) $userId]);

        $revoked = $stmt->rowCount();

        if ($revoked > 0) {
            auth_record_event($conn, 'passkey_bulk_revoke', 'success', [
                'user_role' => $role,
                'user_id' => $userId,
                'detail' => 'count=' . $revoked . ($reason !== '' ? ' reason=' . $reason : ''),
            ]);
        }

        return $revoked;
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn bulk revoke failed: ' . $exception->getMessage());
        return 0;
    }
}

/**
 * Renames a passkey. The ownership predicate is part of the UPDATE so a
 * passkey id belonging to another account cannot be renamed by guessing it.
 */
function webauthn_rename_passkey($conn, string $role, $userId, int $passkeyId, string $label): array
{
    if (!auth_table_exists($conn, 'passkeys')) {
        return ['ok' => false, 'error' => 'Passkey support is unavailable.'];
    }

    $label = trim($label);
    if ($label === '') {
        return ['ok' => false, 'error' => 'Give the passkey a name.'];
    }
    if (mb_strlen($label) > WEBAUTHN_CREDENTIAL_LABEL_MAX) {
        return ['ok' => false, 'error' => 'That name is too long.'];
    }

    $passkeyId = (int) $passkeyId;
    if ($passkeyId <= 0) {
        return ['ok' => false, 'error' => 'Invalid passkey.'];
    }

    try {
        $stmt = $conn->prepare(
            'UPDATE passkeys SET label = ?
             WHERE id = ? AND user_role = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$label, $passkeyId, $role, (int) $userId]);

        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'That passkey was not found on your account.'];
        }
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn rename failed: ' . $exception->getMessage());
        return ['ok' => false, 'error' => 'The passkey could not be renamed.'];
    }

    auth_record_event($conn, 'passkey_rename', 'success', [
        'user_role' => $role,
        'user_id' => $userId,
        'detail' => 'passkey_id=' . $passkeyId,
    ]);

    return ['ok' => true, 'error' => ''];
}

/**
 * Revokes a passkey. The ownership predicate is part of the UPDATE, not a
 * preceding SELECT, so a passkey id belonging to another account cannot be
 * revoked by guessing it.
 *
 * @return array{ok: bool, error: string, remaining: int}
 */
function webauthn_revoke_passkey($conn, string $role, $userId, int $passkeyId): array
{
    if (!auth_table_exists($conn, 'passkeys')) {
        return ['ok' => false, 'error' => 'Passkey support is unavailable.', 'remaining' => 0];
    }

    $passkeyId = (int) $passkeyId;
    if ($passkeyId <= 0) {
        return ['ok' => false, 'error' => 'Invalid passkey.', 'remaining' => 0];
    }

    try {
        $stmt = $conn->prepare(
            'UPDATE passkeys SET revoked_at = ?
             WHERE id = ? AND user_role = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([gmdate('Y-m-d H:i:s'), $passkeyId, $role, (int) $userId]);

        if ($stmt->rowCount() !== 1) {
            return ['ok' => false, 'error' => 'That passkey was not found on your account.', 'remaining' => 0];
        }
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn revoke failed: ' . $exception->getMessage());
        return ['ok' => false, 'error' => 'The passkey could not be removed.', 'remaining' => 0];
    }

    $remaining = 0;
    try {
        $count = $conn->prepare(
            'SELECT COUNT(*) FROM passkeys WHERE user_role = ? AND user_id = ? AND revoked_at IS NULL'
        );
        $count->execute([$role, (int) $userId]);
        $remaining = (int) ($count->get_result()->fetchColumn() ?: 0);
    } catch (Throwable $exception) {
        error_log('EduPortal WebAuthn revoke count failed: ' . $exception->getMessage());
    }

    return ['ok' => true, 'error' => '', 'remaining' => $remaining];
}
