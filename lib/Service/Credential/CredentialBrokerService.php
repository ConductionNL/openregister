<?php

/**
 * CredentialBrokerService — the constrained secret-injecting outbound proxy.
 *
 * Given a `credential` id, an authenticated calling app id, and a method + path,
 * the broker enforces FOUR ordered guards, FAILING CLOSED, then injects the stored
 * secret and performs a single outbound call (design.md D4):
 *
 *   1. Owner    — the current session user owns the credential object (per-object
 *                 IDOR check, ADR-005 Rule 3).
 *   2. Allowed  — the calling app id is in the credential's `allowedApps[]`.
 *   3. Rules    — the method + normalised path match one of the provider's
 *                 `allowRules[]` (exact method, glob path).
 *   4. Host-lock— the resolved URL host equals the provider `baseUrl` host; the
 *                 caller supplies only a path, never a full URL.
 *
 * Only after all four pass does the broker read the secret from the {@see CredentialStore},
 * inject it per the provider's `authScheme`, call the host via `IClientService`
 * (reusing NC's HTTP client — NOT Guzzle, NOT OpenConnector), and return
 * `{status, headers, body}`. The secret NEVER appears in the return value, a log
 * line, or an error. Guard failures throw {@see CredentialAccessDeniedException}
 * (mapped to a static 403); the real reason is logged server-side, secret-free.
 *
 * TRUSTED IN-PROCESS PATH (credential-doriath-leaf design D-G): same-instance
 * PHP consumers (e.g. openconnector, background jobs) call {@see request()}
 * directly, passing their `appId` WITHOUT an HMAC token — token minting and
 * verification ({@see CredentialAppTokenService}) is the CONTROLLER's mechanism
 * for proving app identity across the HTTP boundary, a boundary an in-process
 * call does not cross (a malicious in-process caller could equally forge a
 * token, so it adds no security there). Signed tokens REMAIN required for every
 * HTTP / cross-runtime caller; the controller path is unchanged. All four
 * guards run identically on both paths.
 *
 * BACKGROUND / SYSTEM CONSUMPTION (design D-K): `request()` accepts an optional
 * `actingUserId`, honored ONLY when NO user session exists — the owner guard
 * then evaluates against it. With a session, the session identity wins
 * unconditionally. The HTTP controller NEVER forwards an acting user, so any
 * call carrying one is by construction an in-process PHP caller. The value is
 * an ASSERTION by trusted same-instance code (derive it from durable job
 * context, never request input); the broker applies the full guard chain
 * against it, failing closed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Constrained, host-locked, secret-injecting outbound broker.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The fail-closed guard chain
 *   (scope dispatch + owner/membership + allowedApps + provider rules + host-lock)
 *   is deliberately decomposed into small single-purpose guard methods so each
 *   security decision is independently auditable; the aggregate weighted method
 *   count is a by-product of that decomposition, not of tangled logic.
 */
class CredentialBrokerService
{
    /**
     * Register slug holding `credential` metadata objects.
     *
     * @var string
     */
    public const REGISTER = 'credential-broker';

    /**
     * Schema slug for `credential` metadata objects.
     *
     * @var string
     */
    public const SCHEMA = 'brokeredcredential';

    /**
     * The personal (owner-scoped) credential scope — the default when absent.
     *
     * @var string
     */
    private const SCOPE_PERSONAL = 'personal';

    /**
     * The organisation (membership-scoped) credential scope.
     *
     * @var string
     */
    private const SCOPE_ORGANISATION = 'organisation';

    /**
     * Constructor.
     *
     * @param ObjectService       $objectService       OR object CRUD (loads credential metadata).
     * @param CredentialStore     $credentialStore     Secret store leaf (reads the injected secret).
     * @param ProviderCatalogue   $catalogue           Read-only provider catalogue (host-lock + rules).
     * @param IUserSession        $userSession         Current session (owner guard identity).
     * @param IClientService      $clientService       NC HTTP client factory (the outbound call).
     * @param LoggerInterface     $logger              Logger for secret-free server-side diagnostics.
     * @param OrganisationService $organisationService Resolves organisation membership (organisation guard branch).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly CredentialStore $credentialStore,
        private readonly ProviderCatalogue $catalogue,
        private readonly IUserSession $userSession,
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger,
        private readonly OrganisationService $organisationService,
    ) {
    }//end __construct()

    /**
     * Broker a single constrained outbound call on a credential's behalf.
     *
     * On the HTTP path `appId` comes ONLY from a verified `X-Credential-Token`.
     * In-process same-instance PHP callers pass their `appId` directly without a
     * token (design D-G — the token authenticates claims across the HTTP trust
     * boundary, which an in-process call does not cross). `actingUserId` (design
     * D-K) is honored ONLY when no user session exists: the owner guard then
     * evaluates against it; a session identity wins unconditionally, and the
     * HTTP controller never forwards it.
     *
     * @param string                $credentialId The `credential` object UUID.
     * @param string                $appId        The authenticated calling app id (verified token claim on HTTP; the
     *                                            caller's own app id on the trusted in-process path — never a body field).
     * @param string                $method       The HTTP method (e.g. `GET`).
     * @param string                $path         The provider-relative path (caller supplies ONLY a path, never a full URL).
     * @param array<string, string> $headers      Optional extra request headers (the auth header is broker-controlled).
     * @param string|null           $body         Optional raw request body.
     * @param string|null           $actingUserId Optional asserted user for SESSIONLESS in-process callers only
     *                                            (background jobs); ignored whenever a user session exists.
     *
     * @return array{status: int, headers: array<string, mixed>, body: string} The upstream status, headers, and body.
     *
     * @throws CredentialAccessDeniedException When any guard fails closed (mapped to a static 403).
     * @throws CredentialUpstreamException     When the outbound call fails at the transport level (mapped to a static 502).
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#background-acting-user-resolution
     * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#manifest-driven-credential-app-onboarding
     */
    public function request(
        string $credentialId,
        string $appId,
        string $method,
        string $path,
        array $headers=[],
        ?string $body=null,
        ?string $actingUserId=null
    ): array {
        $method    = strtoupper($method);
        $matchPath = $this->normalisePath(path: $path);

        // Guard 1 — access (per-object IDOR): scope-dispatched owner/membership check.
        $credential = $this->loadAdmittedCredential(credentialId: $credentialId, actingUserId: $actingUserId);
        $data       = $credential->jsonSerialize();
        $scope      = $this->scopeOf(data: $data);

        // Guard 2 — allowed app.
        $this->assertAppAllowed(data: $data, appId: $appId, credentialId: $credentialId);

        // Guard 3 + 4 — provider resolution, allow-rules, host-lock.
        $provider = $this->resolveProvider(data: $data, credentialId: $credentialId);
        $this->assertRuleAllowed(provider: $provider, method: $method, matchPath: $matchPath, credentialId: $credentialId);
        $resolvedUrl = $this->resolveAndLockUrl(provider: $provider, path: $path, credentialId: $credentialId);

        // All guards passed — read the secret (from the scope's vault owner) and perform the call.
        $secret = $this->credentialStore->get($credentialId, $scope);
        if ($secret === null) {
            $this->deny(reason: 'no secret stored for credential', credentialId: $credentialId);
        }

        $requestHeaders = $this->injectAuth(provider: $provider, headers: $headers, secret: (string) $secret);

        return $this->performCall(
            method: $method,
            url: $resolvedUrl,
            headers: $requestHeaders,
            body: $body,
            credentialId: $credentialId
        );
    }//end request()

    /**
     * Guard 1 — load the credential and admit the caller per the credential's SCOPE.
     *
     * The owner guard is scope-dispatched (design D3), and the change is strictly
     * ADDITIVE — the personal branch is byte-for-byte the pre-existing owner check:
     *
     *   - `personal` (or absent): admit only when the acting identity equals the
     *     credential `owner`. The acting identity is the SESSION user whenever one
     *     exists (unconditionally — a session caller cannot impersonate via
     *     `actingUserId`); only a sessionless in-process caller may assert
     *     `actingUserId` (design D-K); no identity at all denies as before.
     *   - `organisation`: admit only when a REAL session user is a member of the
     *     credential's `organisation` (no `actingUserId` fallback — there is no
     *     owner to fall back to, so an unauthenticated organisation call denies).
     *
     * The `allowedApps`, provider allow-rule, and host-lock guards then run for
     * BOTH scopes, unchanged, in {@see request()}.
     *
     * @param string      $credentialId The `credential` object UUID.
     * @param string|null $actingUserId Asserted user for sessionless in-process callers (personal branch only).
     *
     * @return ObjectEntity The admitted credential entity.
     *
     * @throws CredentialAccessDeniedException When missing, unauthenticated, or not admitted.
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
     * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#background-acting-user-resolution
     */
    private function loadAdmittedCredential(string $credentialId, ?string $actingUserId=null): ObjectEntity
    {
        try {
            $credential = $this->objectService->find(
                id: $credentialId,
                register: self::REGISTER,
                schema: self::SCHEMA,
                _rbac: false
            );
        } catch (Throwable $e) {
            $credential = null;
        }

        if ($credential === null) {
            $this->deny(reason: 'credential not found', credentialId: $credentialId);
        }

        $data = $credential->jsonSerialize();
        if ($this->scopeOf(data: $data) === self::SCOPE_ORGANISATION) {
            $this->assertOrganisationMember(data: $data, credentialId: $credentialId);
            return $credential;
        }

        $this->assertPersonalOwner(credential: $credential, credentialId: $credentialId, actingUserId: $actingUserId);

        return $credential;
    }//end loadAdmittedCredential()

    /**
     * Personal owner guard — UNCHANGED: admit only when the acting identity owns it.
     *
     * @param ObjectEntity $credential   The loaded credential entity.
     * @param string       $credentialId The `credential` object UUID (for logging).
     * @param string|null  $actingUserId Asserted user for sessionless in-process callers only.
     *
     * @return void
     *
     * @throws CredentialAccessDeniedException When unauthenticated or not owned.
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
     * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#background-acting-user-resolution
     */
    private function assertPersonalOwner(ObjectEntity $credential, string $credentialId, ?string $actingUserId): void
    {
        $ownerUid = $this->resolveActingIdentity(actingUserId: $actingUserId);
        if ($ownerUid === null) {
            $this->deny(reason: 'unauthenticated', credentialId: $credentialId);
        }

        if ($credential->getOwner() !== $ownerUid) {
            $this->deny(reason: 'caller is not the credential owner', credentialId: $credentialId);
        }
    }//end assertPersonalOwner()

    /**
     * Organisation membership guard — a REAL session user must be a member of the org.
     *
     * There is no owner to fall back to, so an organisation call REQUIRES a real
     * user session: the sessionless `actingUserId` fallback is deliberately not
     * consulted here, and no session denies (design D3). Membership is resolved
     * through {@see OrganisationService::hasAccessToOrganisation()} (the current
     * session user is a member of — or a Nextcloud admin over — the organisation).
     *
     * @param array<string, mixed> $data         The credential's serialised data.
     * @param string               $credentialId The `credential` object UUID (for logging).
     *
     * @return void
     *
     * @throws CredentialAccessDeniedException When sessionless or not a member.
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
     */
    private function assertOrganisationMember(array $data, string $credentialId): void
    {
        if ($this->userSession->getUser() === null) {
            $this->deny(reason: 'organisation credential requires a user session', credentialId: $credentialId);
        }

        $organisation = (string) ($data['organisation'] ?? '');
        if ($organisation === '' || $this->organisationService->hasAccessToOrganisation($organisation) === false) {
            $this->deny(reason: 'caller is not a member of the credential organisation', credentialId: $credentialId);
        }
    }//end assertOrganisationMember()

    /**
     * Resolve a credential's scope from its serialised data (absent ⇒ personal).
     *
     * @param array<string, mixed> $data The credential's serialised data.
     *
     * @return string The scope (`personal`|`organisation`).
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#credential-scope
     */
    private function scopeOf(array $data): string
    {
        $scope = (string) ($data['scope'] ?? self::SCOPE_PERSONAL);
        if ($scope === self::SCOPE_ORGANISATION) {
            return self::SCOPE_ORGANISATION;
        }

        return self::SCOPE_PERSONAL;
    }//end scopeOf()

    /**
     * Resolve the identity the owner guard evaluates against (design D-K).
     *
     * Session identity wins UNCONDITIONALLY when a user session exists —
     * `actingUserId` is ignored there, so a session-context caller can never
     * impersonate another user. Only when no session exists (background /
     * cron, in-process by construction: the HTTP controller never forwards an
     * acting user) is a non-empty `actingUserId` honored. Returns null when
     * neither identity exists (the caller then denies, failing closed).
     *
     * @param string|null $actingUserId Asserted user for sessionless in-process callers only.
     *
     * @return string|null The identity for the owner guard, or null when unauthenticated.
     *
     * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#background-acting-user-resolution
     */
    private function resolveActingIdentity(?string $actingUserId): ?string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        if ($actingUserId !== null && $actingUserId !== '') {
            return $actingUserId;
        }

        return null;
    }//end resolveActingIdentity()

    /**
     * Guard 2 — assert the calling app is in the credential's allow-list.
     *
     * @param array<string, mixed> $data         The credential's serialised data.
     * @param string               $appId        The authenticated calling app id.
     * @param string               $credentialId The credential UUID (for logging).
     *
     * @return void
     *
     * @throws CredentialAccessDeniedException When the app is not allowed.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
     */
    private function assertAppAllowed(array $data, string $appId, string $credentialId): void
    {
        $allowedApps = ($data['allowedApps'] ?? []);
        if (is_array($allowedApps) === false || in_array($appId, $allowedApps, true) === false) {
            $this->deny(reason: 'app "'.$appId.'" not in allowedApps', credentialId: $credentialId);
        }
    }//end assertAppAllowed()

    /**
     * Resolve the credential's provider from the read-only catalogue.
     *
     * @param array<string, mixed> $data         The credential's serialised data.
     * @param string               $credentialId The credential UUID (for logging).
     *
     * @return array<string, mixed> The catalogue provider entry.
     *
     * @throws CredentialAccessDeniedException When the provider is unknown.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    private function resolveProvider(array $data, string $credentialId): array
    {
        $providerId = (string) ($data['provider'] ?? '');
        $provider   = $this->catalogue->get($providerId);
        if ($provider === null) {
            $this->deny(reason: 'unknown provider "'.$providerId.'"', credentialId: $credentialId);
        }

        return $provider;
    }//end resolveProvider()

    /**
     * Guard 3 — assert method + normalised path match one of the provider's allow-rules.
     *
     * @param array<string, mixed> $provider     The catalogue provider entry.
     * @param string               $method       The upper-cased HTTP method.
     * @param string               $matchPath    The normalised path (query stripped).
     * @param string               $credentialId The credential UUID (for logging).
     *
     * @return void
     *
     * @throws CredentialAccessDeniedException When no allow-rule matches.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    private function assertRuleAllowed(array $provider, string $method, string $matchPath, string $credentialId): void
    {
        $rules = ($provider['allowRules'] ?? []);
        if (is_array($rules) === true) {
            foreach ($rules as $rule) {
                if (is_array($rule) === false) {
                    continue;
                }

                $ruleMethod  = strtoupper((string) ($rule['method'] ?? ''));
                $rulePattern = (string) ($rule['pathPattern'] ?? '');
                if ($ruleMethod === $method && $rulePattern !== '' && fnmatch($rulePattern, $matchPath) === true) {
                    return;
                }
            }
        }

        $this->deny(reason: 'no allow-rule matches '.$method.' '.$matchPath, credentialId: $credentialId);
    }//end assertRuleAllowed()

    /**
     * Guard 4 — build the resolved URL and verify its host equals the provider host.
     *
     * The caller supplies only a path; the URL is `baseUrl . path`. The host of the
     * resolved URL MUST equal the host of `baseUrl` — defence-in-depth against a path
     * that tries to smuggle a new authority.
     *
     * @param array<string, mixed> $provider     The catalogue provider entry.
     * @param string               $path         The caller-supplied path (with any query).
     * @param string               $credentialId The credential UUID (for logging).
     *
     * @return string The host-locked resolved URL.
     *
     * @throws CredentialAccessDeniedException When the resolved host does not match the base host.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    private function resolveAndLockUrl(array $provider, string $path, string $credentialId): string
    {
        $baseUrl     = (string) ($provider['baseUrl'] ?? '');
        $resolvedUrl = $baseUrl.$path;

        $baseHost     = parse_url($baseUrl, PHP_URL_HOST);
        $resolvedHost = parse_url($resolvedUrl, PHP_URL_HOST);
        if (is_string($baseHost) === false || $baseHost === '' || $resolvedHost !== $baseHost) {
            $this->deny(reason: 'host-lock violation for provider host "'.(string) $baseHost.'"', credentialId: $credentialId);
        }

        return $resolvedUrl;
    }//end resolveAndLockUrl()

    /**
     * Inject the provider's auth scheme (secret substituted) into the request headers.
     *
     * The broker owns the auth header: any caller-supplied value for that header (and
     * for `Host`) is discarded, then the templated secret header is set.
     *
     * @param array<string, mixed>  $provider The catalogue provider entry.
     * @param array<string, string> $headers  Caller-supplied extra headers.
     * @param string                $secret   The raw secret (never logged).
     *
     * @return array<string, string> The final request headers with auth injected.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    private function injectAuth(array $provider, array $headers, string $secret): array
    {
        $scheme     = ($provider['authScheme'] ?? []);
        $headerName = (string) ($scheme['header'] ?? 'Authorization');
        $template   = (string) ($scheme['template'] ?? '{secret}');

        // Discard any caller attempt to set the auth header or the Host header.
        $sanitised = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower((string) $key);
            if ($lower === strtolower($headerName) || $lower === 'host') {
                continue;
            }

            $sanitised[(string) $key] = (string) $value;
        }

        $sanitised[$headerName] = str_replace('{secret}', $secret, $template);
        return $sanitised;
    }//end injectAuth()

    /**
     * Perform the outbound call and return the upstream status, headers, and body.
     *
     * A non-2xx upstream status is a COMPLETED call and is returned verbatim
     * (`http_errors` disabled). Only a transport-level failure throws.
     *
     * @param string                $method       The upper-cased HTTP method.
     * @param string                $url          The host-locked resolved URL.
     * @param array<string, string> $headers      The final request headers (auth injected).
     * @param string|null           $body         The optional raw request body.
     * @param string                $credentialId The credential UUID (for logging).
     *
     * @return array{status: int, headers: array<string, mixed>, body: string} The upstream response.
     *
     * @throws CredentialUpstreamException When the call fails at the transport level.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    private function performCall(string $method, string $url, array $headers, ?string $body, string $credentialId): array
    {
        $options = [
            'headers'     => $headers,
            'http_errors' => false,
        ];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->request($method, $url, $options);
        } catch (Throwable $e) {
            $this->logger->error(
                '[CredentialBrokerService] upstream call failed',
                ['credential' => $credentialId, 'error' => $e->getMessage()]
            );
            throw new CredentialUpstreamException(message: 'Upstream request failed');
        }

        $rawBody = $response->getBody();
        if (is_resource($rawBody) === true) {
            $rawBody = stream_get_contents($rawBody);
        }

        return [
            'status'  => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body'    => (string) $rawBody,
        ];
    }//end performCall()

    /**
     * Normalise and validate a caller-supplied path (reject `..`, require a single leading `/`).
     *
     * Returns the query-stripped, single-decoded path used for allow-rule matching.
     *
     * @param string $path The caller-supplied path.
     *
     * @return string The normalised path for matching.
     *
     * @throws CredentialAccessDeniedException When the path is empty, protocol-relative, or contains traversal.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    private function normalisePath(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') === true) {
            $this->deny(reason: 'path must be a single-slash-rooted relative path', credentialId: '');
        }

        // Single-decode, then reject any traversal in the decoded form.
        $decoded = rawurldecode($path);
        if (str_contains($decoded, '..') === true) {
            $this->deny(reason: 'path contains traversal', credentialId: '');
        }

        $queryPos = strpos($decoded, '?');
        if ($queryPos !== false) {
            return substr($decoded, 0, $queryPos);
        }

        return $decoded;
    }//end normalisePath()

    /**
     * Log a secret-free reason and throw the static access-denied exception.
     *
     * @param string $reason       The real, secret-free reason (server-side only).
     * @param string $credentialId The credential UUID (for correlation), or empty.
     *
     * @return never
     *
     * @throws CredentialAccessDeniedException Always.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    private function deny(string $reason, string $credentialId): never
    {
        $this->logger->warning(
            '[CredentialBrokerService] request denied: '.$reason,
            ['credential' => $credentialId]
        );
        throw new CredentialAccessDeniedException(message: 'Request not permitted');
    }//end deny()
}//end class
