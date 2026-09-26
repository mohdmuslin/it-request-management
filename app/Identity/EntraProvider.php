<?php

namespace App\Identity;

use App\Contracts\IdentityProvider;
use App\Contracts\UserProfile;
use App\Exceptions\IdentityNotConfiguredException;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sign-in through Microsoft Entra ID, via OpenID Connect.
 *
 * STATUS: WRITTEN, NOT YET RUN AGAINST A TENANT
 *
 * This is a complete implementation of the authorisation-code flow, and **no part of the
 * network interaction with Microsoft has been executed** — there is no app registration yet.
 * That distinction is stated here rather than discovered, because "we wrote an SSO integration"
 * and "we have signed in through SSO" are different claims and only one of them is true.
 *
 * What IS exercised by the test suite: URL construction, configuration validation, state and
 * nonce handling, the failure paths, and the account-matching rules — all against a faked HTTP
 * client. What is NOT: whether Microsoft accepts the request, and whether a real token validates.
 * Both depend on the app registration, which is the outstanding decision.
 *
 * WHY THIS IS NOT A STUB
 *
 * A stub provider that returned a hard-coded user would make the seam *look* complete while
 * proving nothing — the same failure this project has already recorded twice, most recently in
 * D-10 where a designed interface was documented as built. So the flow is real and the limits
 * are named.
 *
 * THE TOKEN IS VALIDATED, NOT TRUSTED
 *
 * The callback carries an `id_token` signed by Microsoft. Accepting it unverified would mean
 * anyone who could reach the callback URL could sign in as anyone — the signature check is not
 * optional hardening, it is the entire security of the flow. It is verified against the tenant's
 * published keys, and `iss`, `aud`, `exp` and `nonce` are all checked.
 */
class EntraProvider implements IdentityProvider
{
    /** Where the browser is sent, and where the code is exchanged, for a given tenant. */
    private const AUTHORITY = 'https://login.microsoftonline.com';

    public function driver(): string
    {
        return 'entra';
    }

    /**
     * Every required setting present.
     *
     * All four matter. A tenant id with no client secret gets as far as the login page and then
     * fails at the token exchange — which is the worst possible place to discover a blank
     * variable, because the error is shown to a user who cannot fix it and looks like a problem
     * with their account.
     */
    public function isConfigured(): bool
    {
        return $this->configurationProblem() === null;
    }

    public function configurationProblem(): ?string
    {
        $missing = [];

        foreach (['tenant_id', 'client_id', 'client_secret', 'redirect'] as $key) {
            if (blank($this->config($key))) {
                $missing[] = 'ENTRA_'.strtoupper($key);
            }
        }

        if ($missing === []) {
            return null;
        }

        return 'Missing '.implode(', ', $missing).'. '
            .'Find these in the Azure portal under App registrations → your app. '
            .'The tenant id is on the Overview page; the client id and secret are on '
            .'Certificates & secrets; the redirect URI must match one registered there exactly.';
    }

    public function requiresRedirect(): bool
    {
        return true;
    }

    public function authenticate(string $email, string $secret): ?User
    {
        /*
         * Not reachable, and it says so.
         *
         * The caller asks `requiresRedirect()` first, so this is only reached by a mistake.
         * Returning null would present that mistake as a wrong password.
         */
        throw IdentityNotConfiguredException::forDriver(
            'entra',
            'Sign-in through Entra happens at Microsoft, not here. Use redirectUrl().'
        );
    }

    /**
     * Where to send the browser.
     *
     * STATE AND NONCE ARE BOTH STORED SERVER-SIDE, NOT IN THE URL
     *
     * A state value echoed back in a URL query string proves only that the value round-tripped,
     * which anyone can arrange. Storing it in the session and comparing on return proves it came
     * back to *this* browser with *this* session — which is what prevents an attacker from
     * feeding a victim an authorisation code obtained elsewhere.
     *
     * The nonce does the same job for the id_token, binding it to this specific request so a
     * token captured from an earlier session cannot be replayed.
     */
    public function redirectUrl(): string
    {
        $this->assertConfigured();

        $state = Str::random(40);
        $nonce = Str::random(40);

        session()->put('entra.state', $state);
        session()->put('entra.nonce', $nonce);

        return $this->endpoint('oauth2/v2.0/authorize').'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->config('redirect'),
            'response_mode' => 'query',
            'scope' => 'openid profile email User.Read',
            'state' => $state,
            'nonce' => $nonce,
        ]);
    }

    /**
     * Complete sign-in from the callback.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function userFromCallback(array $parameters): ?User
    {
        $this->assertConfigured();

        $expectedState = session()->pull('entra.state');
        $expectedNonce = session()->pull('entra.nonce');

        $state = $parameters['state'] ?? null;

        /*
         * The state is checked BEFORE the code is exchanged.
         *
         * Exchanging first and validating later would consume the code and make an API call on
         * behalf of a request that was never ours. The order matters: verify, then act.
         */
        if (! is_string($state) || $expectedState === null || ! hash_equals($expectedState, $state)) {
            Log::warning('Entra callback rejected: state mismatch or absent session.');

            return null;
        }

        if (isset($parameters['error'])) {
            // The user declined consent, or the tenant blocked it. Not an application error.
            Log::info('Entra returned an error to the callback.', [
                'error' => $parameters['error'],
                'description' => $parameters['error_description'] ?? null,
            ]);

            return null;
        }

        $code = $parameters['code'] ?? null;

        if (! is_string($code) || $code === '') {
            Log::warning('Entra callback carried no authorisation code.');

            return null;
        }

        $token = $this->exchangeCodeForToken($code);

        if ($token === null) {
            return null;
        }

        $claims = $this->validateIdToken($token['id_token'] ?? null, $expectedNonce);

        if ($claims === null) {
            return null;
        }

        return $this->matchOrCreateUser($claims);
    }

    /**
     * The profile Entra holds, from the Graph API.
     *
     * FR-002. Department and manager come from the directory rather than from this application,
     * which is the difference from `LocalProvider` — and the reason the contract returns a value
     * object instead of reading three columns.
     *
     * Returns what the application already holds when Graph cannot be reached. A sign-in must not
     * fail because a profile lookup did; the user's record is a reasonable answer, and the next
     * sign-in will try again.
     */
    public function profileFor(User $user): UserProfile
    {
        $fallback = new UserProfile(
            identifier: $user->entra_object_id ?? $user->email,
            email: $user->email,
            name: $user->name,
            employeeNo: $user->employee_no,
            entraObjectId: $user->entra_object_id,
            departmentId: $user->department_id,
            divisionId: $user->division_id,
            managerId: $user->manager_id,
        );

        if (! $this->isConfigured() || blank($user->entra_object_id)) {
            return $fallback;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($this->clientCredentialsToken())
                ->get('https://graph.microsoft.com/v1.0/users/'.$user->entra_object_id, [
                    '$select' => 'id,displayName,mail,userPrincipalName,department,jobTitle,employeeId',
                ]);

            if (! $response->successful()) {
                return $fallback;
            }

            $graph = $response->json();

            /*
             * Department arrives as a NAME, not an id.
             *
             * Entra stores the department as free text — whatever the directory administrator
             * typed — while this application has a `departments` table with codes. Matching by
             * name is the only option available, and it is done case-insensitively because
             * "Information Technology" and "information technology" are the same department to
             * everybody except a database.
             *
             * A name that matches nothing leaves the stored value alone rather than clearing it.
             * The directory not naming a department is not evidence that the user has none.
             */
            $departmentId = $user->department_id;

            if (filled($graph['department'] ?? null)) {
                $departmentId = Department::whereRaw(
                    'lower(name) = ?',
                    [mb_strtolower((string) $graph['department'])]
                )->value('id') ?? $departmentId;
            }

            return new UserProfile(
                identifier: $graph['id'] ?? $fallback->identifier,
                email: $graph['mail'] ?? $graph['userPrincipalName'] ?? $user->email,
                name: $graph['displayName'] ?? $user->name,
                employeeNo: $graph['employeeId'] ?? $user->employee_no,
                entraObjectId: $graph['id'] ?? $user->entra_object_id,
                departmentId: $departmentId,
                divisionId: $user->division_id,
                managerId: $user->manager_id,
            );
        } catch (\Throwable $e) {
            Log::warning('Graph profile lookup failed; using the stored record.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    // ---- Flow internals ----------------------------------------------------

    /** @return array<string, mixed>|null */
    private function exchangeCodeForToken(string $code): ?array
    {
        try {
            $response = Http::asForm()->timeout(15)->post($this->endpoint('oauth2/v2.0/token'), [
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'code' => $code,
                'redirect_uri' => $this->config('redirect'),
                'grant_type' => 'authorization_code',
                'scope' => 'openid profile email User.Read',
            ]);
        } catch (\Throwable $e) {
            Log::error('Entra token exchange could not reach the authority.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            /*
             * The response body is logged, the client secret is not.
             *
             * Entra's error description names the actual problem — a redirect URI mismatch, an
             * expired secret, a code already redeemed — and without it the only symptom is that
             * sign-in does nothing. The request parameters are never logged, because they carry
             * the secret.
             */
            Log::error('Entra token exchange was refused.', [
                'status' => $response->status(),
                'error' => $response->json('error'),
                'description' => $response->json('error_description'),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Verify the id_token and return its claims.
     *
     * WHY THE SIGNATURE IS CHECKED AGAINST PUBLISHED KEYS
     *
     * The token arrives through the user's browser. Without verification, anyone who could reach
     * the callback could post a token claiming to be any address in the organisation — the
     * signature is not hardening on top of the flow, it is the flow's only guarantee.
     *
     * The keys are cached, because fetching them per sign-in would add a round trip to Microsoft
     * on every login and Microsoft rate-limits that endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function validateIdToken(?string $token, ?string $expectedNonce): ?array
    {
        if (! is_string($token) || substr_count($token, '.') !== 2) {
            Log::warning('Entra returned no usable id_token.');

            return null;
        }

        [$header64, $payload64, $signature64] = explode('.', $token);

        if (! $this->signatureIsValid($header64, $payload64, $signature64)) {
            Log::warning('Entra id_token signature did not verify.');

            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($payload64, '-_', '+/'), true), true);

        if (! is_array($claims)) {
            Log::warning('Entra id_token payload was not valid JSON.');

            return null;
        }

        // Expiry, with a minute of leeway for clock skew between here and Microsoft.
        if (($claims['exp'] ?? 0) < (time() - 60)) {
            Log::warning('Entra id_token had expired.');

            return null;
        }

        // The token must have been issued for THIS application, or a token for any other app in
        // the tenant would be accepted.
        $audience = $claims['aud'] ?? null;

        if ($audience !== $this->config('client_id')) {
            Log::warning('Entra id_token audience did not match the client id.', ['aud' => $audience]);

            return null;
        }

        // And by THIS tenant.
        $issuer = (string) ($claims['iss'] ?? '');

        if ($issuer !== '' && ! str_contains($issuer, (string) $this->config('tenant_id'))) {
            Log::warning('Entra id_token issuer did not match the configured tenant.', ['iss' => $issuer]);

            return null;
        }

        // Replay protection: the nonce must be the one this session asked for.
        if ($expectedNonce !== null && ! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            Log::warning('Entra id_token nonce did not match this session.');

            return null;
        }

        return $claims;
    }

    /** Verify RS256 against the tenant's JWKS. */
    private function signatureIsValid(string $header64, string $payload64, string $signature64): bool
    {
        $header = json_decode((string) base64_decode(strtr($header64, '-_', '+/'), true), true);

        if (! is_array($header)) {
            return false;
        }

        /*
         * ONLY RS256.
         *
         * Accepting the algorithm named in the token's own header is the classic JWT attack:
         * `alg: none`, or forging an HMAC using the public key as the secret. Pinning it here
         * means a token that names anything else is rejected before its signature is considered.
         */
        if (($header['alg'] ?? '') !== 'RS256') {
            Log::warning('Entra id_token used an unexpected algorithm.', ['alg' => $header['alg'] ?? null]);

            return false;
        }

        $key = $this->signingKeyFor((string) ($header['kid'] ?? ''));

        if ($key === null) {
            return false;
        }

        $verified = openssl_verify(
            $header64.'.'.$payload64,
            $this->base64UrlDecode($signature64),
            $key,
            OPENSSL_ALGO_SHA256,
        );

        return $verified === 1;
    }

    /** The public key for a `kid`, from the tenant's cached JWKS. */
    private function signingKeyFor(string $kid): ?\OpenSSLAsymmetricKey
    {
        if ($kid === '') {
            return null;
        }

        $keys = Cache::remember('entra.jwks', now()->addHours(12), function () {
            try {
                $response = Http::timeout(10)->get($this->endpoint('discovery/v2.0/keys'));

                return $response->successful() ? ($response->json('keys') ?? []) : [];
            } catch (\Throwable $e) {
                Log::error('Could not fetch Entra signing keys.', ['error' => $e->getMessage()]);

                return [];
            }
        });

        foreach ($keys as $jwk) {
            if (($jwk['kid'] ?? null) !== $kid) {
                continue;
            }

            $pem = $this->jwkToPem((string) ($jwk['n'] ?? ''), (string) ($jwk['e'] ?? ''));

            if ($pem === null) {
                return null;
            }

            return openssl_pkey_get_public($pem) ?: null;
        }

        // Unknown kid. The cache may predate a key rotation, so it is dropped for the next
        // attempt rather than kept — otherwise one rotation locks everybody out until the cache
        // expires.
        Cache::forget('entra.jwks');

        return null;
    }

    /** Build an RSA public key from a JWK's modulus and exponent. */
    private function jwkToPem(string $n, string $e): ?string
    {
        if ($n === '' || $e === '') {
            return null;
        }

        $modulus = $this->asn1Integer($this->base64UrlDecode($n));
        $exponent = $this->asn1Integer($this->base64UrlDecode($e));

        $rsaKey = $this->asn1Sequence($modulus.$exponent);
        $bitString = chr(0x03).$this->asn1Length(strlen($rsaKey) + 1).chr(0x00).$rsaKey;
        $algorithm = $this->asn1Sequence(
            // OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL parameters
            chr(0x06).chr(0x09)."\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01".chr(0x05).chr(0x00)
        );

        $body = $this->asn1Sequence($algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($body), 64, "\n")
            .'-----END PUBLIC KEY-----';
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function asn1Integer(string $bytes): string
    {
        // A leading high bit would read as a negative number, so a zero byte is prepended.
        if ($bytes !== '' && (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00".$bytes;
        }

        return chr(0x02).$this->asn1Length(strlen($bytes)).$bytes;
    }

    private function asn1Sequence(string $bytes): string
    {
        return chr(0x30).$this->asn1Length(strlen($bytes)).$bytes;
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    /**
     * Find the account this identity belongs to, or create one.
     *
     * MATCHED BY OBJECT ID FIRST, EMAIL SECOND.
     *
     * The object id is immutable and never recycled, so it is the correct key. Email changes —
     * on marriage, on a department rename, on a tenant migration — and matching on it alone
     * would, in the worst case, attach a new person's sign-in to a departing employee's record
     * and hand them that person's approvals.
     *
     * The email fallback exists only for the first sign-in after SSO is switched on, when every
     * existing account has a null object id. It stamps the id at that moment, so the fallback
     * runs once per user and never again.
     *
     * @param  array<string, mixed>  $claims
     */
    private function matchOrCreateUser(array $claims): ?User
    {
        $objectId = $claims['oid'] ?? $claims['sub'] ?? null;
        $email = $claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? null;

        if (! is_string($email) || $email === '') {
            Log::warning('Entra id_token carried no email or username claim.');

            return null;
        }

        $user = null;

        if (is_string($objectId) && $objectId !== '') {
            $user = User::where('entra_object_id', $objectId)->first();
        }

        if (! $user) {
            $user = User::where('email', $email)->first();
        }

        /*
         * AN UNKNOWN IDENTITY IS REFUSED, NOT CREATED.
         *
         * Every other part of this application assumes an administrator decided who may do what,
         * and the roles that grant that are assigned by hand. Auto-creating an account would mean
         * anyone in the tenant — including a contractor, a shared mailbox or a compromised
         * account — could reach a request system by signing in once.
         *
         * If self-service provisioning is ever wanted, it belongs behind an explicit setting and
         * a default role that can do nothing, not here as an accident of implementation.
         */
        if (! $user) {
            Log::warning('Entra sign-in refused: no account for this identity.', ['email' => $email]);

            return null;
        }

        // Stamp the object id on first SSO sign-in, so the fallback above is not needed again.
        if (is_string($objectId) && $objectId !== '' && $user->entra_object_id !== $objectId) {
            $user->forceFill(['entra_object_id' => $objectId])->save();
        }

        return $user;
    }

    /** A client-credentials token, for calling Graph. */
    private function clientCredentialsToken(): string
    {
        return Cache::remember('entra.graph_token', now()->addMinutes(50), function () {
            $response = Http::asForm()->timeout(15)->post($this->endpoint('oauth2/v2.0/token'), [
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

            return (string) $response->json('access_token');
        });
    }

    private function endpoint(string $path): string
    {
        return rtrim(self::AUTHORITY, '/').'/'.$this->config('tenant_id').'/'.$path;
    }

    private function config(string $key): ?string
    {
        $value = config('itrequest.identity.entra.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw IdentityNotConfiguredException::forDriver('entra', (string) $this->configurationProblem());
        }
    }
}
