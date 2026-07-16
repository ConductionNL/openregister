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
 * MINTING (app-facing): {@see mint()} is the single path that brings a credential
 * into existence — it writes the metadata object AND the vault secret, atomically
 * from the caller's point of view, and needs no HTTP session. The controller and
 * in-process callers (an openconnector migration repair step folding an inline
 * plaintext secret into the broker) both go through it, so the metadata shape and
 * the vault write live in exactly one place. Authorization for a mint (provider
 * validation, the organisation-administrator gate) stays with the CALLER — those
 * are request/authz concerns, not mint concerns.
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
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Constrained, host-locked, secret-injecting outbound broker.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The fail-closed guard chain
 *   (scope dispatch + owner/membership + allowedApps + provider rules + host-lock)
 *   is deliberately decomposed into small single-purpose guard methods so each
 *   security decision is independently auditable; the aggregate weighted method
 *   count is a by-product of that decomposition, not of tangled logic.
 *
 * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
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
        // TRUST BOUNDARY (openregister#450): request() is HTTP-routed (credential#brokerRequest),
        // so it MUST NEVER let a caller assert an acting organisation — passing null keeps the
        // sessionless organisation admit path (assertOrganisationMember) unreachable from a routed
        // request, exactly as the controller passes no actingUserId. A sessionless proxy call
        // against an organisation credential therefore still denies; only the non-routed
        // resolveInjectable() path may carry an in-process organisation assertion.
        $credential = $this->loadAdmittedCredential(
            credentialId: $credentialId,
            actingUserId: $actingUserId,
            actingOrganisationId: null
        );
        $data       = $credential->jsonSerialize();
        $scope      = $this->scopeOf(data: $data);

        // Guard 2 — allowed app.
        $this->assertAppAllowed(data: $data, appId: $appId, credentialId: $credentialId);

        // Guard 3 + 4 — provider resolution, allow-rules, host-lock.
        $provider = $this->resolveProvider(data: $data, credentialId: $credentialId);

        // An inject-only provider carries no baseUrl/allowRules and MUST NEVER be proxied
        // (an unbounded host is exactly what the constrained proxy exists to prevent). Its
        // secret is reachable only app-side via resolveInjectable(); fail closed here.
        if ($this->isInjectOnly(provider: $provider) === true) {
            $this->deny(reason: 'inject-only provider cannot be proxied; use resolveInjectable', credentialId: $credentialId);
        }

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
     * Resolve the raw secret for an INJECT-ONLY credential, for same-instance app-side injection.
     *
     * This is the deliberate counterpart to {@see request()} for arbitrary / self-hosted
     * targets that cannot be host-locked from the immutable catalogue (an OpenConnector
     * Source against a municipality's own API, say). It runs ONLY the two guards that are
     * meaningful without a host — Guard 1 (owner / organisation membership, IDOR) and Guard 2
     * (allowedApps) — and then returns the plaintext secret to the trusted in-process caller,
     * which injects it itself. It deliberately SKIPS the provider allow-rules and host-lock
     * (Guards 3 + 4): there is no host to lock, so those guards do not apply.
     *
     * The catalogue keeps the two worlds apart. An `inject_only` provider carries no baseUrl
     * and no allowRules, so {@see request()} refuses it (it can never become an open proxy).
     * A normal host-locked provider (Mollie, GitHub, …) is NOT inject-only, so this method
     * returns null for it — its secret stays zero-knowledge inside OR and is only ever
     * reachable through the constrained proxy. A caller therefore uses the return value to
     * route: a non-null secret means "inject this yourself"; null means "this credential is a
     * proxy credential — call request() instead".
     *
     * The plaintext crosses the process boundary into the calling app (a change from the
     * proxy's "secret never leaves OR" posture), so this is a TRUSTED IN-PROCESS PATH only —
     * `appId` is the caller's own id, exactly as on the tokenless in-process {@see request()}
     * path (design D-G). `actingUserId` follows the same rule as request() (design D-K):
     * honored only when there is no user session.
     *
     * SESSIONLESS ORGANISATION RESOLUTION (openregister#450, ADR-064 Rule 4): an
     * `organisation`-scoped INFRASTRUCTURE credential (a source/consumer secret) MUST NOT be
     * personal-scoped, yet its trusted in-process consumers — an openconnector migration repair
     * step, a background sync job — run with NO user session. Such a caller may assert
     * `actingOrganisationId`; the organisation guard then admits iff it MATCHES the credential's
     * organisation (see {@see assertOrganisationMember()}). This is honored ONLY without a
     * session and, exactly like `actingUserId`, is settable only by in-process code — this
     * method is NOT HTTP-routed, so request input can never reach it.
     *
     * @param string      $credentialId         The `credential` object UUID.
     * @param string      $appId                The authenticated calling app id (the caller's own id on the in-process path).
     * @param string|null $actingUserId         Optional asserted user for SESSIONLESS in-process callers; ignored with a session.
     * @param string|null $actingOrganisationId Optional asserted organisation for SESSIONLESS in-process callers resolving an
     *                                          organisation credential; admits iff it equals the credential's organisation, and
     *                                          ignored whenever a user session exists. NEVER settable from request input (this
     *                                          method is not routed) — an in-process trust assertion only.
     *
     * @return string|null The raw secret for an inject-only credential, or null when the credential is a proxy credential.
     *
     * @throws CredentialAccessDeniedException When Guard 1 or 2 fails, or an inject-only credential has no stored secret.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
     * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#manifest-driven-credential-app-onboarding
     */
    public function resolveInjectable(
        string $credentialId,
        string $appId,
        ?string $actingUserId=null,
        ?string $actingOrganisationId=null
    ): ?string {
        // Guard 1 — access (per-object IDOR): scope-dispatched owner/membership check.
        $credential = $this->loadAdmittedCredential(
            credentialId: $credentialId,
            actingUserId: $actingUserId,
            actingOrganisationId: $actingOrganisationId
        );
        $data       = $credential->jsonSerialize();
        $scope      = $this->scopeOf(data: $data);

        // Guard 2 — allowed app.
        $this->assertAppAllowed(data: $data, appId: $appId, credentialId: $credentialId);

        // Only inject-only credentials may be resolved app-side; a proxy credential's secret
        // stays inside OR — signal that with null so the caller routes to request() instead.
        $provider = $this->resolveProvider(data: $data, credentialId: $credentialId);
        if ($this->isInjectOnly(provider: $provider) === false) {
            return null;
        }

        $secret = $this->credentialStore->get($credentialId, $scope);
        if ($secret === null) {
            $this->deny(reason: 'no secret stored for inject-only credential', credentialId: $credentialId);
        }

        return (string) $secret;
    }//end resolveInjectable()

    /**
     * Mint a credential: persist its metadata object and store its secret to the vault.
     *
     * The single mint path for the whole instance — the HTTP controller and any
     * in-process caller (an openconnector migration repair step folding an inline
     * plaintext source secret into the broker, say) go through here, so the metadata
     * shape, the vault write, and the atomicity contract are defined exactly once.
     *
     * SESSIONLESS BY CONSTRUCTION: no `IUserSession` is consulted — the `$owner` is
     * an ASSERTION by the trusted caller (the controller passes the session uid; a
     * repair step derives it from durable job context, never from request input).
     * This mirrors the `actingUserId` posture of {@see request()} / {@see resolveInjectable()}
     * (design D-K) and lets a background job mint without an HTTP session.
     *
     * AUTHORIZATION IS THE CALLER'S: minting is not itself a guarded operation — the
     * provider-catalogue validation and the organisation-administrator gate are
     * request/authz concerns that live in {@see \OCA\OpenRegister\Controller\CredentialController}.
     * This method takes an ALREADY-RESOLVED `$scope` + `$organisation` and trusts them.
     *
     * ATOMICITY: the metadata object and the vault secret are two stores with no shared
     * transaction. When the vault write fails AFTER the object was saved we would be left
     * with a metadata object that no secret backs — a credential that looks usable in the
     * UI and fails closed at broker time with "no secret stored". Rather than ship that,
     * the orphaned object is deleted and the original vault failure is rethrown, so a mint
     * either yields a complete credential or yields nothing. A failure to delete the orphan
     * is logged (secret-free) and does not mask the original error.
     *
     * The `$secret` NEVER reaches an OR object, a log line, or the return value — it is
     * handed straight to the {@see CredentialStore} leaf and otherwise untouched
     * (the CredentialStore contract).
     *
     * @param string             $name         The human-readable credential name (non-empty).
     * @param string             $provider     The provider identifier (the CALLER validates it against the catalogue).
     * @param string             $owner        The owning user's UID (asserted by the trusted caller; never request input).
     * @param array<int, string> $allowedApps  The app ids permitted to use this credential (Guard 2 at broker time).
     * @param string|null        $secret       The raw secret, or null/'' to mint metadata only (no vault write).
     * @param string             $scope        The ALREADY-RESOLVED scope (`personal`|`organisation`); selects the vault owner.
     * @param string|null        $organisation The ALREADY-GATED owning organisation UUID; required for the organisation scope.
     *
     * @return ObjectEntity The persisted credential entity (its `getUuid()` is the credential id / credentialRef).
     *
     * @throws \InvalidArgumentException When the name is empty, or an organisation-scoped mint carries no organisation.
     * @throws Throwable                 When the object save or the vault write fails (the orphaned object is removed first).
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-secret-storage
     */
    public function mint(
        string $name,
        string $provider,
        string $owner,
        array $allowedApps=[],
        ?string $secret=null,
        string $scope=self::SCOPE_PERSONAL,
        ?string $organisation=null
    ): ObjectEntity {
        $name = trim($name);
        if ($name === '' || trim($provider) === '') {
            throw new InvalidArgumentException(message: 'A credential requires a name and a provider');
        }

        $data = [
            'name'        => $name,
            'provider'    => $provider,
            'owner'       => $owner,
            'allowedApps' => array_values($allowedApps),
            'createdAt'   => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        // Only an organisation-scoped credential carries scope/organisation — a personal
        // credential's property bag stays byte-for-byte what it has always been (design D1).
        if ($scope !== self::SCOPE_ORGANISATION) {
            $scope = self::SCOPE_PERSONAL;
        }

        if ($scope === self::SCOPE_ORGANISATION) {
            $organisation = trim((string) $organisation);
            if ($organisation === '') {
                throw new InvalidArgumentException(message: 'An organisation-scoped credential requires an organisation');
            }

            $data['scope']        = self::SCOPE_ORGANISATION;
            $data['organisation'] = $organisation;
        }

        $saved = $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        $uuid = (string) $saved->getUuid();
        if ($secret === null || $secret === '') {
            return $saved;
        }

        try {
            $this->credentialStore->put($uuid, $secret, $scope);
        } catch (Throwable $e) {
            $this->discardOrphanedCredential(uuid: $uuid);
            throw $e;
        }

        return $saved;
    }//end mint()

    /**
     * Remove a credential metadata object whose vault write failed (mint rollback).
     *
     * Best-effort by design: the caller is already unwinding a vault failure and that
     * error must reach it unmasked, so a delete failure is logged (secret-free) and
     * swallowed. Worst case the orphan survives and fails closed at broker time.
     *
     * @param string $uuid The orphaned credential object's UUID.
     *
     * @return void
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    private function discardOrphanedCredential(string $uuid): void
    {
        try {
            $this->objectService->deleteObject(
                uuid: $uuid,
                register: self::REGISTER,
                schema: self::SCHEMA,
                _rbac: false
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Credential broker: failed to roll back credential {uuid} after its secret could not be stored',
                ['uuid' => $uuid, 'exception' => $e]
            );
        }
    }//end discardOrphanedCredential()

    /**
     * Whether a resolved provider entry is inject-only (app-side injection, never proxied).
     *
     * @param array<string, mixed> $provider The catalogue provider entry.
     *
     * @return bool True when the provider is flagged `inject_only`.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    private function isInjectOnly(array $provider): bool
    {
        return ($provider['inject_only'] ?? false) === true;
    }//end isInjectOnly()

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
     *   - `organisation`: admit when a REAL session user is a member of the
     *     credential's `organisation`, OR — for a SESSIONLESS trusted in-process
     *     caller (openregister#450) — when the asserted `actingOrganisationId`
     *     matches the credential's `organisation`. The `actingUserId` fallback is
     *     never consulted for the organisation branch (org scope is deliberately
     *     decoupled from any one user's membership, ADR-064 Rule 4).
     *
     * The `allowedApps`, provider allow-rule, and host-lock guards then run for
     * BOTH scopes, unchanged, in {@see request()}.
     *
     * @param string      $credentialId         The `credential` object UUID.
     * @param string|null $actingUserId         Asserted user for sessionless in-process callers (personal branch only).
     * @param string|null $actingOrganisationId Asserted organisation for sessionless in-process callers (organisation branch only).
     *
     * @return ObjectEntity The admitted credential entity.
     *
     * @throws CredentialAccessDeniedException When missing, unauthenticated, or not admitted.
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
     * @spec openspec/changes/credential-doriath-leaf/specs/credential-broker/spec.md#background-acting-user-resolution
     */
    private function loadAdmittedCredential(
        string $credentialId,
        ?string $actingUserId=null,
        ?string $actingOrganisationId=null
    ): ObjectEntity {
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
            $this->assertOrganisationMember(
                data: $data,
                credentialId: $credentialId,
                actingOrganisationId: $actingOrganisationId
            );
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
     * Organisation membership guard — a session member OR a matching sessionless in-process assertion.
     *
     * ADR-064 Rule 4 REQUIRES an infrastructure credential (a source/consumer secret) to be
     * `organisation`-scoped, never `personal` — coupling it to one employee would break the
     * integration the moment that employee leaves. But its trusted in-process consumers (an
     * openconnector migration repair step, a background sync job) run with NO user session, so
     * the original design D3 "no session denies" rule left org-scoped infrastructure credentials
     * unresolvable (openregister#450). This guard therefore admits on TWO paths:
     *
     *   - Session present: the session is AUTHORITATIVE. Membership is resolved through
     *     {@see OrganisationService::hasAccessToOrganisation()} (the session user is a member of —
     *     or a Nextcloud admin over — the organisation), exactly as before. Any asserted
     *     `actingOrganisationId` is IGNORED here, so a request-context caller can NEVER escalate
     *     via the assertion (a session is proof of identity; the assertion is not).
     *   - Sessionless: admit IFF a non-null `actingOrganisationId` EQUALS the credential's
     *     `organisation`. This is the only new admit path. It is safe because the assertion is
     *     settable only by trusted in-process code — request input never reaches it (the routed
     *     {@see request()} passes null; the controller never reads it; {@see resolveInjectable()}
     *     is not HTTP-routed). The match is a consistency guard so an org-A assertion cannot
     *     resolve an org-B credential; resolution stays DECOUPLED from any individual user's
     *     membership, which is the entire point of organisation scope (ADR-064 Rule 4). The
     *     sessionless `actingUserId` fallback is deliberately NOT reused — org scope must not be
     *     recoupled to a single user.
     *
     * @param array<string, mixed> $data                 The credential's serialised data.
     * @param string               $credentialId         The `credential` object UUID (for logging).
     * @param string|null          $actingOrganisationId Asserted organisation for sessionless in-process callers only.
     *
     * @return void
     *
     * @throws CredentialAccessDeniedException When the org is malformed, the session user is not a member, or the
     *                                         sessionless assertion is absent or does not match the credential organisation.
     *
     * @spec openspec/changes/credential-broker-organisation-scope/specs/credential-broker/spec.md#organisation-broker-guard
     */
    private function assertOrganisationMember(array $data, string $credentialId, ?string $actingOrganisationId=null): void
    {
        $organisation = (string) ($data['organisation'] ?? '');
        if ($organisation === '') {
            $this->deny(reason: 'organisation credential has no organisation', credentialId: $credentialId);
        }

        // Session present: the session identity is authoritative and the asserted acting
        // organisation is ignored — membership is resolved exactly as before.
        if ($this->userSession->getUser() !== null) {
            if ($this->organisationService->hasAccessToOrganisation($organisation) === false) {
                $this->deny(reason: 'caller is not a member of the credential organisation', credentialId: $credentialId);
            }

            return;
        }

        // Sessionless (in-process by construction — request input never reaches
        // actingOrganisationId). Admit only when the trusted caller asserts THIS
        // credential's organisation; matching decouples resolution from any one user.
        if ($actingOrganisationId === null || $actingOrganisationId !== $organisation) {
            $this->deny(
                reason: 'sessionless organisation resolution requires a matching acting organisation',
                credentialId: $credentialId
            );
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
