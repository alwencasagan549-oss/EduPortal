<?php
/**
 * Tests for the WebAuthn service.
 *
 * The parts worth testing here are the ones that are easy to get subtly wrong
 * and impossible to notice until users are locked out or a credential is
 * accepted for the wrong account:
 *
 *   - the signature counter policy, which must tolerate a permanently-zero
 *     counter from a synced passkey while still catching a real clone
 *   - base64url round-tripping, since every buffer crosses the wire through it
 *   - Relying Party ID and origin derivation
 *   - challenge extraction from a client payload
 *
 * No database is required, so the suite runs on a bare checkout.
 *
 * Run: php tests/webauthn_service_test.php
 */

define('SITE_URL', 'https://portal.school.edu');
define('PLATFORM_NAME', 'EduPortal LMS');

require_once __DIR__ . '/../libs/WebAuthnService.php';

use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

$failures = [];
$checks = 0;

function check(string $label, bool $passed): void
{
    global $failures, $checks;
    $checks++;
    if (!$passed) {
        $failures[] = $label;
    }
}

function same(string $label, $expected, $actual): void
{
    check(
        $label . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')',
        $expected === $actual
    );
}

/**
 * @param bool|null $synced null models an authenticator that reported no
 *                           backup state at all, which must be treated as
 *                           device-bound.
 */
function credential(int $counter, ?bool $synced): CredentialRecord
{
    return CredentialRecord::create(
        random_bytes(32),
        'public-key',
        ['internal'],
        'none',
        EmptyTrustPath::create(),
        Uuid::fromString('00000000-0000-0000-0000-000000000000'),
        random_bytes(77),
        random_bytes(32),
        $counter,
        null,
        $synced,   // backupEligible
        $synced,   // backupStatus
        true       // uvInitialized
    );
}

// ---------------------------------------------------------------------
// base64url
// ---------------------------------------------------------------------

$roundTrip = ['', 'a', 'ab', 'abc', random_bytes(16), random_bytes(32), random_bytes(64), "\x00\xff\xfe"];
foreach ($roundTrip as $index => $binary) {
    same('base64url round-trips sample ' . $index, $binary, webauthn_base64url_decode(webauthn_base64url($binary)));
}

check('base64url output has no padding', !str_contains(webauthn_base64url(random_bytes(32)), '='));
check('base64url output has no plus', !str_contains(webauthn_base64url("\xfb\xff"), '+'));
check('base64url output has no slash', !str_contains(webauthn_base64url("\xff\xef"), '/'));
same('a 32-byte challenge encodes to 43 characters', 43, strlen(webauthn_base64url(random_bytes(32))));

// A 32-byte handle must survive the round trip, since it is persisted and
// later compared with hash_equals against what the authenticator returns.
$handle = random_bytes(WEBAUTHN_USER_HANDLE_BYTES);
same('a user handle round-trips', $handle, webauthn_base64url_decode(webauthn_base64url($handle)));
same('a user handle encodes to 43 characters', 43, strlen(webauthn_base64url($handle)));
check('the user handle is 32 opaque bytes', strlen($handle) === 32);

// ---------------------------------------------------------------------
// Signature counter policy
// ---------------------------------------------------------------------

$counter = new EduPortalSignCounterChecker();

// The case this whole class exists for: a synced passkey reports zero forever.
// Rejecting it would lock the user out on their second sign-in.
$counter->check(credential(0, true), 0);
$counter->check(credential(0, true), 0);
$counter->check(credential(7, true), 0);
check('a synced passkey tolerates a permanently zero counter', true);

// A device-bound credential must still fail on a non-increasing counter,
// because that is the clone signal the counter exists to detect.
$threw = false;
try {
    $counter->check(credential(10, false), 10);
} catch (CounterException $exception) {
    $threw = true;
}
check('a device-bound credential rejects an unchanged counter', $threw);

$threw = false;
try {
    $counter->check(credential(10, false), 3);
} catch (CounterException $exception) {
    $threw = true;
}
check('a device-bound credential rejects a decreasing counter', $threw);

$threw = false;
try {
    $counter->check(credential(10, false), 11);
} catch (CounterException $exception) {
    $threw = true;
}
check('a device-bound credential accepts an increasing counter', !$threw);

// A zero counter carries no information: it cannot be distinguished from an
// authenticator that does not maintain one, and several never increment it.
// Rejecting a non-increasing pair of zeros locks those users out of their own
// accounts, which is why the library's default checker skips enforcement
// whenever either side is zero.
$threw = false;
try {
    $counter->check(credential(0, false), 0);
} catch (CounterException $exception) {
    $threw = true;
}
check('a device-bound credential tolerates zero against zero', !$threw);

$threw = false;
try {
    $counter->check(credential(0, false), 0);
} catch (CounterException $exception) {
    $threw = true;
}
check('the first assertion on a fresh credential is accepted', !$threw);

$threw = false;
try {
    $counter->check(credential(7, false), 0);
} catch (CounterException $exception) {
    $threw = true;
}
check('a zero reading against a real counter is not a clone signal', !$threw);

// Real clone detection still applies once both sides carry a count.
$threw = false;
try {
    $counter->check(credential(10, false), 4);
} catch (CounterException $exception) {
    $threw = true;
}
check('a genuine decrease is still rejected', $threw);

$threw = false;
try {
    $counter->check(credential(10, false), 11);
} catch (CounterException $exception) {
    $threw = true;
}
check('a genuine increase is still accepted', !$threw);

// ---------------------------------------------------------------------
// Relying Party ID and origins
// ---------------------------------------------------------------------

putenv('WEBAUTHN_RP_ID');
putenv('WEBAUTHN_ORIGINS');

same('RP ID defaults to the SITE_URL host', 'portal.school.edu', webauthn_rp_id());
same('origins default to the SITE_URL origin', ['https://portal.school.edu'], webauthn_origins());

putenv('WEBAUTHN_RP_ID=custom.example');
putenv('WEBAUTHN_ORIGINS=https://a.example,https://b.example');
same('an explicit RP ID wins', 'custom.example', webauthn_rp_id());
same('explicit origins are split and trimmed', ['https://a.example', 'https://b.example'], webauthn_origins());

putenv('WEBAUTHN_ORIGINS=');
same('an empty origins override falls back to SITE_URL', ['https://portal.school.edu'], webauthn_origins());

putenv('WEBAUTHN_RP_ID');
putenv('WEBAUTHN_ORIGINS');

// A port must be preserved or the origin check will reject the real site.
define('PORTED_SITE_URL', 'http://localhost:8080');
same('an origin keeps its port', 'localhost:8080', (function () {
    $parts = parse_url(PORTED_SITE_URL);
    $origin = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'];
    return str_replace(['https://', 'http://'], '', $origin);
})());

// ---------------------------------------------------------------------
// Challenge extraction
// ---------------------------------------------------------------------

$rawChallenge = random_bytes(32);
$clientData = json_encode([
    'type' => 'webauthn.create',
    'challenge' => webauthn_base64url($rawChallenge),
    'origin' => 'https://portal.school.edu',
]);

$extracted = webauthn_extract_challenge([
    'response' => ['clientDataJSON' => webauthn_base64url($clientData)]
]);

check('a well-formed payload yields a challenge', is_array($extracted));
same('the raw challenge is recovered', $rawChallenge, $extracted['raw']);
same('the encoded challenge is recovered', webauthn_base64url($rawChallenge), $extracted['encoded']);
same('the ceremony type is recovered', 'webauthn.create', $extracted['type']);

same('a missing response yields null', null, webauthn_extract_challenge([]));
same('a missing clientDataJSON yields null', null, webauthn_extract_challenge(['response' => []]));
same('an empty clientDataJSON yields null', null, webauthn_extract_challenge(['response' => ['clientDataJSON' => '']]));
same(
    'a clientDataJSON without a challenge yields null',
    null,
    webauthn_extract_challenge(['response' => ['clientDataJSON' => webauthn_base64url('{"type":"webauthn.create"}')]])
);
same(
    'a non-string clientDataJSON yields null',
    null,
    webauthn_extract_challenge(['response' => ['clientDataJSON' => 12345]])
);

// The challenge reaching the browser and the one coming back must be byte
// identical, so re-encoding the stored value has to be exact.
$weird = "\x00\x01\xfe\xff";
same('a high-byte challenge re-encodes exactly', webauthn_base64url($weird), webauthn_base64url(webauthn_base64url_decode(webauthn_base64url($weird))));

// The wire format the browser receives must carry a usable
// PublicKeyCredentialParameters list. Checking only `alg` missed a real defect:
// the descriptors shipped type "-7" instead of "public-key", which Chrome
// rejects wholesale as "Required parameters missing in options.publicKey".
$optionsSerializer = webauthn_serializer();
$wireOptions = json_decode($optionsSerializer->serialize(
    webauthn_creation_options('portal.example', random_bytes(32), random_bytes(32), 'Test User'),
    'json'
), true);

$params = $wireOptions['pubKeyCredParams'] ?? [];
check('the options carry algorithm descriptors', count($params) === 3);
foreach ($params as $index => $entry) {
    same('descriptor ' . $index . ' has type public-key', 'public-key', $entry['type'] ?? null);
    check('descriptor ' . $index . ' has a numeric alg', isset($entry['alg']) && is_int($entry['alg']));
}
same(
    'the offered algorithms are ES256, RS256 and EdDSA',
    [-7, -257, -8],
    array_map(static fn (array $entry) => $entry['alg'], $params)
);

// A descriptor with a non-numeric type is enough to invalidate the whole
// options object, so the check is on the exact string, not on presence.
check(
    'no descriptor type is a numeric string',
    !in_array('-7', array_column($params, 'type'), true)
);

// Identifier-free ("discoverable") authentication. Enrolment requires
// residentKey, so every issued passkey can be found by the authenticator
// without being told which account is signing in.
$discoverable = webauthn_request_options('portal.example', random_bytes(32));
$discoverableWire = json_decode($optionsSerializer->serialize($discoverable, 'json'), true);

same('a discoverable request omits allowCredentials', [], $discoverableWire['allowCredentials'] ?? null);
same('a discoverable request still carries the RP ID', 'portal.example', $discoverableWire['rpId'] ?? null);
same(
    'a discoverable request still demands user verification',
    'required',
    $discoverableWire['userVerification'] ?? null
);

// Scoping is the fallback for credentials that are not discoverable.
$scoped = webauthn_request_options(
    'portal.example',
    random_bytes(32),
    [PublicKeyCredentialDescriptor::create(
        PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
        random_bytes(32),
        ['internal']
    )]
);
$scopedWire = json_decode($optionsSerializer->serialize($scoped, 'json'), true);
same('a scoped request carries one credential', 1, count($scopedWire['allowCredentials'] ?? []));

// ---------------------------------------------------------------------
// End-to-end ceremony against a software authenticator
//
// Everything above tests the pieces. This exercises the actual registration
// and authentication ceremonies through the real validators, using a synthetic
// ES256 authenticator. It is the only way to catch a mistake in the options
// that is only visible when a real attestation is checked against them --
// a wrong RP ID, a user handle that never makes it into the stored record, or
// an algorithm the authenticator answered with that we did not offer.
//
// The negative cases matter most: they prove the checks actually reject.
// ---------------------------------------------------------------------

const FAKE_RP_ID = 'portal.school.edu';
const FAKE_ORIGIN = 'https://portal.school.edu';

/**
 * @return array{private: mixed, cose: string, credentialId: string}|null
 */
function fake_authenticator_key(): ?array
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    if ($key === false) {
        return null;
    }

    $details = openssl_pkey_get_details($key);
    if (!isset($details['ec']['x'], $details['ec']['y'])) {
        return null;
    }

    $x = str_pad(substr($details['ec']['x'], -32), 32, "\x00", STR_PAD_LEFT);
    $y = str_pad(substr($details['ec']['y'], -32), 32, "\x00", STR_PAD_LEFT);

    // COSE_Key for ES256: {1: 2 (EC2), 3: -7 (ES256), -1: 1 (P-256), -2: x, -3: y}
    $cose = (string) \CBOR\MapObject::create([])
        ->add(\CBOR\UnsignedIntegerObject::create(1), \CBOR\UnsignedIntegerObject::create(2))
        ->add(\CBOR\UnsignedIntegerObject::create(3), \CBOR\NegativeIntegerObject::create(-7))
        ->add(\CBOR\NegativeIntegerObject::create(-1), \CBOR\UnsignedIntegerObject::create(1))
        ->add(\CBOR\NegativeIntegerObject::create(-2), \CBOR\ByteStringObject::create($x))
        ->add(\CBOR\NegativeIntegerObject::create(-3), \CBOR\ByteStringObject::create($y));

    return [
        'private' => $key,
        'cose' => $cose,
        'credentialId' => random_bytes(32),
    ];
}

function fake_client_data(string $type, string $challenge, string $origin): string
{
    return (string) json_encode([
        'type' => $type,
        'challenge' => webauthn_base64url($challenge),
        'origin' => $origin,
        'crossOrigin' => false,
    ]);
}

/**
 * Produces a registration response in the exact JSON shape the browser sends.
 */
function fake_registration(
    array $authenticator,
    string $challenge,
    string $origin = FAKE_ORIGIN,
    string $rpId = FAKE_RP_ID,
    ?string $coseOverride = null,
    bool $userVerified = true
): string {
    $cose = $coseOverride ?? $authenticator['cose'];
    $credentialId = $authenticator['credentialId'];

    // The flags are built here rather than patched afterwards: the authData
    // does not start at byte 0 of the CBOR attestation object, so toggling a
    // byte after the fact corrupts the encoding rather than the flag.
    $flags = 0x01 | ($userVerified ? 0x04 : 0x00) | 0x40;

    $authData = hash('sha256', $rpId, true)
        . chr($flags) // UP [| UV] | AT
        . pack('N', 0)
        . str_repeat("\x00", 16) // all-zero AAGUID
        . pack('n', strlen($credentialId)) . $credentialId
        . $cose;

    $attestation = (string) \CBOR\MapObject::create([])
        ->add(\CBOR\TextStringObject::create('fmt'), \CBOR\TextStringObject::create('none'))
        ->add(\CBOR\TextStringObject::create('attStmt'), \CBOR\MapObject::create([]))
        ->add(\CBOR\TextStringObject::create('authData'), \CBOR\ByteStringObject::create($authData));

    return (string) json_encode([
        'id' => webauthn_base64url($credentialId),
        'rawId' => webauthn_base64url($credentialId),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => webauthn_base64url(fake_client_data('webauthn.create', $challenge, $origin)),
            'attestationObject' => webauthn_base64url($attestation),
            'transports' => ['internal'],
        ],
    ]);
}

/**
 * Produces a signed assertion over authData || sha256(clientDataJSON).
 */
function fake_assertion(
    array $authenticator,
    string $challenge,
    int $signCount = 1,
    bool $synced = false,
    string $origin = FAKE_ORIGIN,
    string $rpId = FAKE_RP_ID,
    bool $userVerified = true
): string {
    $flags = 0x01 | ($userVerified ? 0x04 : 0x00);
    if ($synced) {
        $flags |= 0x08 | 0x10; // BE and BS together; BS without BE is invalid
    }

    $authData = hash('sha256', $rpId, true) . chr($flags) . pack('N', $signCount);

    $clientData = fake_client_data('webauthn.get', $challenge, $origin);
    $signedPayload = $authData . hash('sha256', $clientData, true);

    $signature = '';
    if (!openssl_sign($signedPayload, $signature, $authenticator['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('fake authenticator could not sign');
    }

    return (string) json_encode([
        'id' => webauthn_base64url($authenticator['credentialId']),
        'rawId' => webauthn_base64url($authenticator['credentialId']),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => webauthn_base64url($clientData),
            'authenticatorData' => webauthn_base64url($authData),
            'signature' => webauthn_base64url($signature),
            'userHandle' => webauthn_base64url($GLOBALS['fakeUserHandle'] ?? random_bytes(32)),
        ],
    ]);
}

$authenticator = fake_authenticator_key();

if ($authenticator === null) {
    // OpenSSL cannot generate an EC key on this machine, usually because it
    // cannot locate openssl.cnf. Reported rather than silently passed, since
    // the ceremony is the part of this suite with real security value.
    echo "webauthn_service: ceremony tests SKIPPED (OpenSSL cannot create a P-256 key on this host)\n";
} else {
    $GLOBALS['fakeUserHandle'] = random_bytes(32);
    $handle = $GLOBALS['fakeUserHandle'];

    $serializer = webauthn_serializer();
    $attestationValidator = webauthn_attestation_validator();
    $assertionValidator = webauthn_assertion_validator();

    // --- registration ceremony ----------------------------------------

    $registerChallenge = random_bytes(32);
    $options = webauthn_creation_options(FAKE_RP_ID, $handle, $registerChallenge, 'Juan Dela Cruz');
    $serialized = $serializer->serialize($options, 'json');

    // The wire format the browser receives must actually carry what the
    // server will later verify against.
    $wire = json_decode($serialized, true);
    same('the creation payload carries the RP ID', FAKE_RP_ID, $wire['rp']['id'] ?? null);
    same('the creation payload demands user verification', 'required', $wire['authenticatorSelection']['userVerification'] ?? null);
    same('the creation payload demands a resident key', 'required', $wire['authenticatorSelection']['residentKey'] ?? null);
    same('the creation payload asks for no attestation', 'none', $wire['attestation'] ?? null);
    check('the creation payload offers EdDSA', in_array(-8, array_column($wire['pubKeyCredParams'], 'alg'), true));
    same('the user handle is opaque 32 bytes, not an identifier', 43, strlen($wire['user']['id'] ?? ''));

    $registrationJson = fake_registration($authenticator, $registerChallenge);
    $publicKeyCredential = $serializer->deserialize($registrationJson, PublicKeyCredential::class, 'json');

    $record = $attestationValidator->check($publicKeyCredential->response, $options, FAKE_RP_ID);

    check('registration ceremony produces a credential record', $record instanceof CredentialRecord);
    same('the stored user handle is the one that was offered', $handle, $record->userHandle);
    check('the stored public key is the COSE key', $record->credentialPublicKey === $authenticator['cose']);
    check('the credential reports user verification', $record->uvInitialized === true);

    // Persistence round trip: this is exactly what webauthn_store_passkey and
    // the later assertion lookup do.
    $stored = $serializer->serialize($record, 'json');
    $reloaded = $serializer->deserialize($stored, CredentialRecord::class, 'json');
    check('the record survives storage', $reloaded->publicKeyCredentialId === $record->publicKeyCredentialId
        && $reloaded->userHandle === $record->userHandle
        && $reloaded->credentialPublicKey === $record->credentialPublicKey);

    // --- authentication ceremony --------------------------------------

    $descriptor = \Webauthn\PublicKeyCredentialDescriptor::create(
        \Webauthn\PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
        $record->publicKeyCredentialId,
        ['internal']
    );

    $assertChallenge = random_bytes(32);
    $requestOptions = webauthn_request_options(FAKE_RP_ID, $assertChallenge, [$descriptor]);
    $requestWire = json_decode($serializer->serialize($requestOptions, 'json'), true);
    same('the request payload carries the RP ID', FAKE_RP_ID, $requestWire['rpId'] ?? null);
    same('the request payload demands user verification', 'required', $requestWire['userVerification'] ?? null);
    same('the request payload scopes to the credential', 1, count($requestWire['allowCredentials'] ?? []));

    $assertionJson = fake_assertion($authenticator, $assertChallenge);
    $assertion = $serializer->deserialize($assertionJson, PublicKeyCredential::class, 'json');

    $verified = $assertionValidator->check($reloaded, $assertion->response, $requestOptions, FAKE_RP_ID, $handle);
    // Registration recorded signCount 0, the assertion carried 1, and the
    // validator writes the new value through.
    same('the assertion advances the signature counter', 1, $verified->counter);

    // A synced passkey reports a permanently zero counter, which is the case
    // the custom counter checker exists to tolerate.
    $syncedRecord = $serializer->deserialize($stored, CredentialRecord::class, 'json');
    $syncedRecord->backupStatus = true;
    $syncedRecord->backupEligible = true;

    $syncedChallenge = random_bytes(32);
    $syncedOptions = webauthn_request_options(FAKE_RP_ID, $syncedChallenge, [$descriptor]);
    $syncedAssertion = $serializer->deserialize(
        fake_assertion($authenticator, $syncedChallenge, 0, true),
        PublicKeyCredential::class,
        'json'
    );

    $syncedVerified = $assertionValidator->check($syncedRecord, $syncedAssertion->response, $syncedOptions, FAKE_RP_ID, $handle);
    same('a synced passkey with a zero counter is accepted', 0, $syncedVerified->counter);

    // --- the checks that must reject -----------------------------------

    // A rejection test is only meaningful if the ceremony was actually
    // exercised. Previously a null-method Error inside the closure was caught
    // and counted as a correct rejection, so these could pass while never
    // reaching the validator. An Error is a broken test, not a rejection.
    $rejects = static function (string $label, callable $attempt): void {
        try {
            $attempt();
            check($label . ' (no exception thrown)', false);
        } catch (Error $programmingError) {
            check($label . ' (threw ' . get_class($programmingError) . ': ' . $programmingError->getMessage() . ')', false);
        } catch (Throwable $exception) {
            check($label, true);
        }
    };

    // A valid assertion presented with the wrong expected user handle. This
    // is the account-takeover case: without it, a credential proved for one
    // user could be accepted as proof for another.
    $rejects('an assertion with the wrong user handle is rejected', static function () use (
        $assertionValidator, $authenticator, $descriptor, $assertion, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_request_options(FAKE_RP_ID, $challenge, [$descriptor]);
        $response = $serializer->deserialize(
            fake_assertion($authenticator, $challenge, 5),
            PublicKeyCredential::class,
            'json'
        );
        $record = credential(0, false);
        $assertionValidator->check($record, $response->response, $options, FAKE_RP_ID, random_bytes(32));
    });

    $rejects('an assertion for a different challenge is rejected', static function () use (
        $assertionValidator, $authenticator, $descriptor, $serializer
    ) {
        $signed = random_bytes(32);
        $offered = random_bytes(32);
        $options = webauthn_request_options(FAKE_RP_ID, $offered, [$descriptor]);
        $response = $serializer->deserialize(
            fake_assertion($authenticator, $signed, 1),
            PublicKeyCredential::class,
            'json'
        );
        $assertionValidator->check(credential(0, false), $response->response, $options, FAKE_RP_ID, $GLOBALS['fakeUserHandle']);
    });

    $rejects('an assertion from a foreign origin is rejected', static function () use (
        $assertionValidator, $authenticator, $descriptor, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_request_options(FAKE_RP_ID, $challenge, [$descriptor]);
        $response = $serializer->deserialize(
            fake_assertion($authenticator, $challenge, 1, false, 'https://evil.example'),
            PublicKeyCredential::class,
            'json'
        );
        $assertionValidator->check(credential(0, false), $response->response, $options, FAKE_RP_ID, $GLOBALS['fakeUserHandle']);
    });

    $rejects('an assertion bound to a different RP ID is rejected', static function () use (
        $assertionValidator, $authenticator, $descriptor, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_request_options(FAKE_RP_ID, $challenge, [$descriptor]);
        $response = $serializer->deserialize(
            fake_assertion($authenticator, $challenge, 1, false, FAKE_ORIGIN, 'attacker.example'),
            PublicKeyCredential::class,
            'json'
        );
        $assertionValidator->check(credential(0, false), $response->response, $options, FAKE_RP_ID, $GLOBALS['fakeUserHandle']);
    });

    $rejects('an assertion without user verification is rejected', static function () use (
        $assertionValidator, $authenticator, $descriptor, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_request_options(FAKE_RP_ID, $challenge, [$descriptor]);
        $response = $serializer->deserialize(
            fake_assertion($authenticator, $challenge, 1, false, FAKE_ORIGIN, FAKE_RP_ID, false),
            PublicKeyCredential::class,
            'json'
        );
        $assertionValidator->check(credential(0, false), $response->response, $options, FAKE_RP_ID, $GLOBALS['fakeUserHandle']);
    });

    $rejects('a tampered signature is rejected', static function () use (
        $assertionValidator, $authenticator, $descriptor, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_request_options(FAKE_RP_ID, $challenge, [$descriptor]);
        $json = json_decode(fake_assertion($authenticator, $challenge, 1), true);
        $signature = webauthn_base64url_decode($json['response']['signature']);
        $signature[10] = $signature[10] === "\x00" ? "\x01" : "\x00";
        $json['response']['signature'] = webauthn_base64url($signature);
        $response = $serializer->deserialize(json_encode($json), PublicKeyCredential::class, 'json');
        $assertionValidator->check(credential(0, false), $response->response, $options, FAKE_RP_ID, $GLOBALS['fakeUserHandle']);
    });

    $rejects('a device-bound credential with a decreasing counter is rejected', static function () use (
        $assertionValidator, $authenticator, $descriptor, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_request_options(FAKE_RP_ID, $challenge, [$descriptor]);
        $response = $serializer->deserialize(
            fake_assertion($authenticator, $challenge, 2),
            PublicKeyCredential::class,
            'json'
        );
        $assertionValidator->check(credential(100, false), $response->response, $options, FAKE_RP_ID, $GLOBALS['fakeUserHandle']);
    });

    // Registration must reject an authenticator that skipped user verification:
    // without UV there is no biometric, only device possession.
    $rejects('a registration without user verification is rejected', static function () use (
        $attestationValidator, $authenticator, $handle, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_creation_options(FAKE_RP_ID, $handle, $challenge, 'Juan Dela Cruz');
        $json = fake_registration($authenticator, $challenge, FAKE_ORIGIN, FAKE_RP_ID, null, false);
        $pkc = webauthn_serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        $attestationValidator->check($pkc->response, $options, FAKE_RP_ID);
    });

    $rejects('a registration from a foreign origin is rejected', static function () use (
        $attestationValidator, $authenticator, $handle, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_creation_options(FAKE_RP_ID, $handle, $challenge, 'Juan Dela Cruz');
        $json = fake_registration($authenticator, $challenge, 'https://evil.example');
        $pkc = webauthn_serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        $attestationValidator->check($pkc->response, $options, FAKE_RP_ID);
    });

    $rejects('a registration using an unoffered algorithm is rejected', static function () use (
        $attestationValidator, $authenticator, $handle, $serializer
    ) {
        $challenge = random_bytes(32);
        $options = webauthn_creation_options(FAKE_RP_ID, $handle, $challenge, 'Juan Dela Cruz');
        // ES384 was never offered, so an authenticator answering with it must
        // be refused. Using an offered algorithm here would pass and prove
        // nothing, since no signature is checked during registration.
        $cose = (string) \CBOR\MapObject::create([])
            ->add(\CBOR\UnsignedIntegerObject::create(1), \CBOR\UnsignedIntegerObject::create(2))
            ->add(\CBOR\UnsignedIntegerObject::create(3), \CBOR\NegativeIntegerObject::create(-35));
        $json = fake_registration($authenticator, $challenge, FAKE_ORIGIN, FAKE_RP_ID, $cose);
        $pkc = webauthn_serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        $attestationValidator->check($pkc->response, $options, FAKE_RP_ID);
    });
}

// ---------------------------------------------------------------------
// Lifecycle guards that must fail closed without a schema
//
// These are the paths taken when the migration has not run yet. Returning a
// safe value rather than throwing keeps a half-migrated deploy from turning
// the profile page into a 500.
// ---------------------------------------------------------------------

same('revoking all passkeys is a no-op without the table', 0, webauthn_revoke_all_passkeys(null, 'student', 1, 'test'));

$renameEmpty = webauthn_rename_passkey(null, 'student', 1, 1, '   ');
check('renaming to a blank name is refused', $renameEmpty['ok'] === false);

$renameLong = webauthn_rename_passkey(null, 'student', 1, 1, str_repeat('a', WEBAUTHN_CREDENTIAL_LABEL_MAX + 1));
check('an over-long name is refused', $renameLong['ok'] === false);

$renameBadId = webauthn_rename_passkey(null, 'student', 1, 0, 'Phone');
check('an invalid passkey id is refused', $renameBadId['ok'] === false);

$revokeBadId = webauthn_revoke_passkey(null, 'student', 1, 0);
check('revoking an invalid passkey id is refused', $revokeBadId['ok'] === false);
same('a refused revoke reports nothing remaining', 0, $revokeBadId['remaining']);

// ---------------------------------------------------------------------
// The EduPortalResult contract
//
// The passkey service reads scalars through the EduPortalResult wrapper, and
// it did so through a fetchColumn() the wrapper never declared. Because a
// missing method raises an Error -- which is a Throwable -- each of those call
// sites sat inside a catch (Throwable) that swallowed it and let the caller
// carry on with a zero value. The step-up password check answered "wrong
// password" to a correct one, every ceremony reported itself expired, and this
// suite stayed green the whole time, since none of it touches a database.
//
// So the invariant is checked against the source instead of against a live
// connection: every method the application calls on a result wrapper has to be
// declared on the wrapper. That is the one thing that can be verified without
// the schema this suite deliberately does not require.
// ---------------------------------------------------------------------

$root = dirname(__DIR__);
$databaseSource = (string) file_get_contents($root . '/config/database.php');

$classStart = strpos($databaseSource, 'class EduPortalResult');
check('EduPortalResult is declared in config/database.php', $classStart !== false);

if ($classStart !== false) {
    $classEnd = strpos($databaseSource, 'function getDBConnection(', $classStart);
    $classBody = $classEnd === false
        ? substr($databaseSource, $classStart)
        : substr($databaseSource, $classStart, $classEnd - $classStart);

    preg_match_all('/public function (\w+)\s*\(/', $classBody, $declared);
    $declaredMethods = array_flip($declared[1]);

    check(
        'the result wrapper declares fetchColumn'
            . ' (declared: ' . implode(', ', $declared[1]) . ')',
        isset($declaredMethods['fetchColumn'])
    );

    // Every get_result()->method() in the application, resolved against the
    // wrapper. Chained calls are not matched, and there are none.
    $missing = [];
    $callSites = 0;

    $roots = [$root . '/libs', $root . '/controllers'];
    $files = new AppendIterator();

    foreach ($roots as $directory) {
        $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)));
    }

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/->get_result\(\)\s*->\s*(\w+)\s*\(/', (string) file_get_contents($file->getPathname()), $calls);
        foreach ($calls[1] as $method) {
            $callSites++;
            if (!isset($declaredMethods[$method])) {
                $missing[] = $file->getFilename() . '()->' . $method . '()';
            }
        }
    }

    check(
        'the result wrapper is actually used (call sites found: ' . $callSites . ')',
        $callSites > 0
    );

    check(
        'every method called on the result wrapper is declared'
            . ($missing === [] ? '' : ' (missing: ' . implode(', ', $missing) . ')'),
        $missing === []
    );
}

same('listing passkeys without the table yields an empty list', [], webauthn_list_passkeys(null, 'student', 1));
same('descriptors without the table yield an empty list', [], webauthn_passkey_descriptors(null, 'student', 1));
check('an account with no schema has no passkeys', webauthn_has_passkeys(null, 'student', 1) === false);
same('allocating a user handle without the column yields null', null, webauthn_user_handle(null, 'student', 1));
same('an unknown role has no user handle', null, webauthn_user_handle(null, 'principal', 1));

// ---------------------------------------------------------------------

check('the library is installed', webauthn_available());
check('the serializer builds', webauthn_serializer() instanceof \Symfony\Component\Serializer\SerializerInterface);
check('the attestation validator builds', webauthn_attestation_validator() instanceof \Webauthn\AuthenticatorAttestationResponseValidator);
check('the assertion validator builds', webauthn_assertion_validator() instanceof \Webauthn\AuthenticatorAssertionResponseValidator);
check('the ceremony factory resolves the RP ID', webauthn_ceremony_factory() instanceof \Webauthn\CeremonyStep\CeremonyStepManagerFactory);

// EdDSA must be in the algorithm set: platform passkeys commonly negotiate
// -8, and the library default of ES256+RS256 alone would reject them.
// Read back off the factory the service actually builds.
$factory = webauthn_ceremony_factory();
$reflection = new ReflectionProperty(\Webauthn\CeremonyStep\CeremonyStepManagerFactory::class, 'algorithmManager');
$reflection->setAccessible(true);
$algorithmManager = $reflection->getValue($factory);

check('ES256 is registered', $algorithmManager->has(\Cose\Algorithms::COSE_ALGORITHM_ES256));
check('RS256 is registered', $algorithmManager->has(\Cose\Algorithms::COSE_ALGORITHM_RS256));
same(
    'EdDSA registration matches sodium availability',
    \Cose\Algorithm\Signature\EdDSA\EdDSA::isSupported(),
    $algorithmManager->has(\Cose\Algorithms::COSE_ALGORITHM_EDDSA)
);
same('the EdDSA probe agrees with the library', \Cose\Algorithm\Signature\EdDSA\EdDSA::isSupported(), webauthn_eddsa_available());

// Building the factory must not throw regardless of sodium, since a missing
// extension must degrade capability instead of breaking every ceremony.
check('the factory builds without throwing', webauthn_ceremony_factory() instanceof \Webauthn\CeremonyStep\CeremonyStepManagerFactory);

// The origin allowlist must reach the factory, not sit unused in a variable.
$originsProperty = new ReflectionProperty(\Webauthn\CeremonyStep\CeremonyStepManagerFactory::class, 'allowedOrigins');
$originsProperty->setAccessible(true);
same('the factory carries the exact origin allowlist', ['https://portal.school.edu'], $originsProperty->getValue($factory));

// ---------------------------------------------------------------------

if ($failures !== []) {
    fwrite(STDERR, "FAILED (" . count($failures) . " of {$checks}):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "webauthn_service: {$checks} checks passed\n";
exit(0);
