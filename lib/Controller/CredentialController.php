<?php

/**
 * CredentialController — owner-scoped credential CRUD + the guarded broker endpoint.
 *
 * Exposes the credential-broker HTTP surface (design.md D4/D5):
 *   - GET    /api/credentials                       list the caller's own credentials (metadata only)
 *   - POST   /api/credentials                       create a credential + store its secret to the vault
 *   - PUT    /api/credentials/{id}                  update metadata / allowedApps (owner only), optional rotation
 *   - DELETE /api/credentials/{id}                  delete the object + the vault secret (owner only)
 *   - POST   /api/credentials/apps/{appId}/register register/rotate an app signing secret (admin only; returned once)
 *   - POST   /api/credentials/{id}/request          the broker call (app token in the X-Credential-Token header)
 *   - POST   /api/credentials/{id}/session-request  the browser broker call (session auth + CSRF; app id in the body)
 *
 * Every endpoint is `#[NoAdminRequired]` with a per-object owner IDOR guard (ADR-005);
 * identity comes only from `IUserSession`. For the token broker call the app id comes
 * ONLY from the verified signed token; for the session broker call it comes from the
 * request body but the owner guard still fires against the session user. The stored
 * secret is NEVER returned in any response; client errors are static and generic.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * HTTP controller for owner-scoped credentials and the constrained broker call.
 */
class CredentialController extends Controller
{
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
     * @param string                    $appName             App name (injected by NC).
     * @param IRequest                  $request             Current request.
     * @param IUserSession              $userSession         Current session (owner identity).
     * @param IGroupManager             $groupManager        Group manager (admin check for app registration).
     * @param ObjectService             $objectService       OR object CRUD for credential metadata.
     * @param CredentialStore           $credentialStore     Secret store leaf (vault).
     * @param ProviderCatalogue         $catalogue           Read-only provider catalogue (validation).
     * @param CredentialBrokerService   $broker              The guarded outbound broker.
     * @param CredentialAppTokenService $tokenService        Per-app signing-secret registry + token verify.
     * @param OrganisationService       $organisationService Organisation membership + admin authority resolution.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Composes the credential-broker collaborators.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ObjectService $objectService,
        private readonly CredentialStore $credentialStore,
        private readonly ProviderCatalogue $catalogue,
        private readonly CredentialBrokerService $broker,
        private readonly CredentialAppTokenService $tokenService,
        private readonly OrganisationService $organisationService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * GET /api/credentials — list credential metadata (never a secret).
     *
     * `?scope=organisation` lists the caller's active organisation's credentials
     * (visible to any member — D4). No scope, or `?scope=personal`, lists the
     * caller's OWN personal credentials, unchanged.
     *
     * @return JSONResponse The credential metadata (metadata only; never a secret).
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->requestedScope() === self::SCOPE_ORGANISATION) {
            return $this->indexOrganisation();
        }

        try {
            $this->objectService->setRegister(CredentialBrokerService::REGISTER)->setSchema(CredentialBrokerService::SCHEMA);
            $objects = $this->objectService->findAll();
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Unable to list credentials'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $own = [];
        foreach ($objects as $object) {
            $data = $this->serialise(object: $object);
            // Personal listing: the caller's own personal (scope-absent) credentials only.
            if (($data['scope'] ?? self::SCOPE_PERSONAL) === self::SCOPE_ORGANISATION) {
                continue;
            }

            if (($data['@self']['owner'] ?? null) === $uid) {
                $own[] = $data;
            }
        }

        return new JSONResponse(['results' => $own]);
    }//end index()

    /**
     * List the caller's active organisation's credential metadata (members may read).
     *
     * Returns the organisation-scoped credentials whose `organisation` equals the
     * caller's active organisation UUID. The caller is a member of their own active
     * organisation by construction, so any member may read this metadata; no secret
     * is ever included (design D4).
     *
     * @return JSONResponse The active organisation's credential metadata.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function indexOrganisation(): JSONResponse
    {
        $activeOrg = $this->organisationService->getActiveOrganisation()?->getUuid();
        if ($activeOrg === null || $activeOrg === '') {
            return new JSONResponse(['results' => []]);
        }

        try {
            $this->objectService->setRegister(CredentialBrokerService::REGISTER)->setSchema(CredentialBrokerService::SCHEMA);
            $objects = $this->objectService->findAll();
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Unable to list credentials'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $results = [];
        foreach ($objects as $object) {
            $data = $this->serialise(object: $object);
            if (($data['scope'] ?? self::SCOPE_PERSONAL) !== self::SCOPE_ORGANISATION) {
                continue;
            }

            if ((string) ($data['organisation'] ?? '') === $activeOrg) {
                $results[] = $data;
            }
        }

        return new JSONResponse(['results' => $results]);
    }//end indexOrganisation()

    /**
     * GET /api/credentials/providers — list the read-only provider catalogue (id + title only).
     *
     * Powers the settings-UI provider picker. Exposes ONLY the identifier and title — never a
     * secret (there is none in the catalogue) and never the allow-rules (an internal guardrail).
     *
     * @return JSONResponse `{results: Array<{identifier, title}>}`.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    public function providers(): JSONResponse
    {
        if ($this->currentUid() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $out = [];
        foreach ($this->catalogue->all() as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $identifier = (string) ($entry['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }

            $out[] = [
                'identifier' => $identifier,
                'title'      => (string) ($entry['title'] ?? $identifier),
            ];
        }

        return new JSONResponse(['results' => $out]);
    }//end providers()

    /**
     * POST /api/credentials — create a credential and store its secret to the vault.
     *
     * Body: `{name, provider, allowedApps?, secret?, scope?, organisation?}`. The
     * secret (if given) is written straight to the vault under the new object UUID
     * (its scope's vault owner) — never persisted to the OR object.
     *
     * This method owns only the REQUEST half — parsing, provider-catalogue validation,
     * and the organisation-administrator gate. The mint itself (metadata object + vault
     * secret, atomic) belongs to {@see CredentialBrokerService::mint()} so an in-process
     * caller without an HTTP session mints through the exact same path (ADR-022).
     *
     * Personal (scope absent / `personal`): unchanged — any authenticated user may
     * create their own. Organisation (`scope = organisation`): the `organisation`
     * defaults to the caller's active organisation when omitted, and the caller MUST
     * be an administrator of that organisation (or a Nextcloud admin) — design D4.
     *
     * @return JSONResponse The created credential metadata, or a static error.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $name     = trim((string) $this->request->getParam('name', ''));
        $provider = (string) $this->request->getParam('provider', '');
        $secret   = $this->request->getParam('secret');
        $allowed  = $this->normaliseAllowedApps(value: $this->request->getParam('allowedApps', []));
        $scope    = $this->requestedScope();

        if ($name === '' || $this->catalogue->get($provider) === null) {
            return new JSONResponse(['message' => 'Invalid credential request'], Http::STATUS_BAD_REQUEST);
        }

        // Organisation scope: resolve + admin-gate the owning organisation (D4). This is a
        // request/authz concern and stays here; the broker is handed the resolved value.
        $organisation = null;
        if ($scope === self::SCOPE_ORGANISATION) {
            $organisation = $this->gatedOrganisation(uid: $uid);
            if ($organisation instanceof JSONResponse) {
                return $organisation;
            }
        }

        if (is_string($secret) === false) {
            $secret = null;
        }

        try {
            // The single mint path (metadata object + vault secret) lives in the broker so
            // an in-process caller can mint identically without an HTTP session.
            $saved = $this->broker->mint(
                name: $name,
                provider: $provider,
                owner: $uid,
                allowedApps: $allowed,
                secret: $secret,
                scope: $scope,
                organisation: $organisation
            );
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Unable to create credential'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($this->serialise(object: $saved), Http::STATUS_CREATED);
    }//end create()

    /**
     * PUT /api/credentials/{id} — update metadata / allowedApps (owner only); optional rotation.
     *
     * @param string $id The credential UUID.
     *
     * @return JSONResponse The updated credential metadata, or a static error.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $uid      = $this->currentUid();
        $existing = $this->ensureManageable(id: $id, uid: $uid);
        if ($existing instanceof JSONResponse) {
            return $existing;
        }

        // Raw property bag (no `@self` envelope / `id`) — the update carries only
        // metadata, never a secret (there is none in the object).
        $data  = $existing->getObject();
        $scope = $this->scopeOf(data: $data);

        $nameParam = $this->request->getParam('name');
        if (is_string($nameParam) === true && trim($nameParam) !== '') {
            $data['name'] = trim($nameParam);
        }

        if ($this->request->getParam('allowedApps') !== null) {
            $data['allowedApps'] = $this->normaliseAllowedApps(value: $this->request->getParam('allowedApps', []));
        }

        try {
            $saved = $this->objectService->saveObject(
                object: $data,
                register: CredentialBrokerService::REGISTER,
                schema: CredentialBrokerService::SCHEMA,
                uuid: $id
            );
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Unable to update credential'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $secret = $this->request->getParam('secret');
        if (is_string($secret) === true && $secret !== '') {
            $this->credentialStore->put($id, $secret, $scope);
        }

        return new JSONResponse($this->serialise(object: $saved));
    }//end update()

    /**
     * DELETE /api/credentials/{id} — delete the object and its vault secret (owner only).
     *
     * @param string $id The credential UUID.
     *
     * @return JSONResponse An empty success payload, or a static error.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $uid      = $this->currentUid();
        $existing = $this->ensureManageable(id: $id, uid: $uid);
        if ($existing instanceof JSONResponse) {
            return $existing;
        }

        $scope = $this->scopeOf(data: $existing->getObject());

        try {
            $this->objectService->deleteObject(
                uuid: $id,
                register: CredentialBrokerService::REGISTER,
                schema: CredentialBrokerService::SCHEMA
            );
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Unable to delete credential'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->credentialStore->delete($id, $scope);

        return new JSONResponse(['message' => 'Deleted']);
    }//end destroy()

    /**
     * POST /api/credentials/apps/{appId}/register — register/rotate an app signing secret.
     *
     * Admin-only (safest testable posture): the returned secret is shown ONCE and is
     * never retrievable afterward. Consuming apps use it to sign their broker tokens.
     *
     * @param string $appId The consuming app's id.
     *
     * @return JSONResponse `{appId, secret}` once, or a static error.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    public function registerApp(string $appId): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null || $this->groupManager->isAdmin($uid) === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        if (preg_match('/^[a-z0-9_-]+$/', $appId) !== 1) {
            return new JSONResponse(['message' => 'Invalid app id'], Http::STATUS_BAD_REQUEST);
        }

        $secret = $this->tokenService->registerApp(appId: $appId);

        return new JSONResponse(['appId' => $appId, 'secret' => $secret], Http::STATUS_CREATED);
    }//end registerApp()

    /**
     * POST /api/credentials/{id}/request — the guarded broker call.
     *
     * The calling app id is taken ONLY from the verified `X-Credential-Token` header
     * (never a body field). Body: `{method, path, headers?, body?}`.
     *
     * NEVER forwards an acting user (credential-doriath-leaf design D-K): on the
     * HTTP path identity is session-only — the broker's optional `actingUserId`
     * parameter is deliberately NOT read from any request field here, so any
     * caller-supplied acting-user value is ignored entirely and a broker call
     * carrying one is by construction an in-process PHP caller.
     *
     * @param string $id The credential UUID.
     *
     * @return JSONResponse `{status, headers, body}` from the upstream, or a static error.
     *
     * @spec openspec/specs/credential-broker/spec.md
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function brokerRequest(string $id): JSONResponse
    {
        if ($this->currentUid() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $token  = $this->request->getHeader('X-Credential-Token');
        $method = (string) $this->request->getParam('method', 'GET');
        $path   = (string) $this->request->getParam('path', '');

        try {
            // Pass method+path so a request-bound token (harden-credential-token-binding)
            // is only accepted for the exact call it was minted for. Unbound tokens
            // ignore these (backward-compatible).
            $claims = $this->tokenService->verify(token: $token, method: $method, path: $path);
            if ($claims['credentialId'] !== $id) {
                throw new CredentialAccessDeniedException(message: 'token/credential mismatch');
            }

            $result = $this->broker->request(
                credentialId: $id,
                appId: $claims['appId'],
                method: $method,
                path: $path,
                headers: $this->normaliseHeaders(value: $this->request->getParam('headers', [])),
                body: $this->normaliseBody(value: $this->request->getParam('body'))
            );
        } catch (CredentialAccessDeniedException $e) {
            return new JSONResponse(['message' => 'Request not permitted'], Http::STATUS_FORBIDDEN);
        } catch (CredentialUpstreamException $e) {
            return new JSONResponse(['message' => 'Upstream request failed'], Http::STATUS_BAD_GATEWAY);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Request not permitted'], Http::STATUS_FORBIDDEN);
        }//end try

        return new JSONResponse($result);
    }//end brokerRequest()

    /**
     * POST /api/credentials/{id}/session-request — the session-authenticated browser broker call.
     *
     * The browser-side counterpart to {@see brokerRequest()}: a manifest-driven app's
     * frontend (nc-vue) makes a constrained brokered outbound call through OpenRegister
     * WITHOUT holding the secret and WITHOUT minting a signed HMAC token. Identity is the
     * logged-in Nextcloud SESSION user and the request MUST carry the CSRF `requesttoken`
     * — this method is deliberately NOT `#[NoCSRFRequired]`, the key difference from the
     * token endpoint which is CSRF-exempt for server / cross-runtime callers.
     *
     * The calling app id is taken from the `appId` body field (the manifest app id); the
     * owner IDOR guard still fires because the broker resolves the owner identity from the
     * SESSION user (design D-K: session identity wins unconditionally), so a user can never
     * use a credential they do not own via this endpoint. No `actingUserId` is ever
     * forwarded — the session path lets the broker evaluate the owner guard against the
     * current session user naturally.
     *
     * Body: `{appId, method?, path, headers?, body?}`.
     *
     * @param string $id The credential UUID.
     *
     * @return JSONResponse `{status, headers, body}` from the upstream, or a static error.
     *
     * @spec openspec/specs/credential-broker/spec.md
     * @spec openspec/specs/credential-broker/spec.md
     */
    #[NoAdminRequired]
    public function sessionBrokerRequest(string $id): JSONResponse
    {
        if ($this->currentUid() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $appId = trim((string) $this->request->getParam('appId', ''));
        if ($appId === '') {
            return new JSONResponse(['message' => 'Request not permitted'], Http::STATUS_FORBIDDEN);
        }

        try {
            $result = $this->broker->request(
                credentialId: $id,
                appId: $appId,
                method: (string) $this->request->getParam('method', 'GET'),
                path: (string) $this->request->getParam('path', ''),
                headers: $this->normaliseHeaders(value: $this->request->getParam('headers', [])),
                body: $this->normaliseBody(value: $this->request->getParam('body'))
            );
        } catch (CredentialAccessDeniedException $e) {
            return new JSONResponse(['message' => 'Request not permitted'], Http::STATUS_FORBIDDEN);
        } catch (CredentialUpstreamException $e) {
            return new JSONResponse(['message' => 'Upstream request failed'], Http::STATUS_BAD_GATEWAY);
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Request not permitted'], Http::STATUS_FORBIDDEN);
        }//end try

        return new JSONResponse($result);
    }//end sessionBrokerRequest()

    /**
     * Resolve the current user's id, or null when unauthenticated.
     *
     * @return string|null The current UID, or null.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function currentUid(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end currentUid()

    /**
     * Load a credential and enforce the scope-aware management guard (D4).
     *
     * Personal (scope absent / `personal`): the per-object owner IDOR guard,
     * UNCHANGED — the caller must be the credential `owner`. Organisation
     * (`scope = organisation`): the caller must be an administrator of the
     * credential's `organisation` (or a Nextcloud admin).
     *
     * @param string      $id  The credential UUID.
     * @param string|null $uid The current user's UID (null when unauthenticated).
     *
     * @return ObjectEntity|JSONResponse The manageable entity, or a static error response.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function ensureManageable(string $id, ?string $uid): ObjectEntity | JSONResponse
    {
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $object = $this->objectService->find(
                id: $id,
                register: CredentialBrokerService::REGISTER,
                schema: CredentialBrokerService::SCHEMA,
                _rbac: false
            );
        } catch (Throwable $e) {
            $object = null;
        }

        if ($object === null) {
            // Uniform 403 — never distinguish "missing" from "not yours".
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        if ($this->scopeOf(data: $object->getObject()) === self::SCOPE_ORGANISATION) {
            $organisation = (string) ($object->getObject()['organisation'] ?? '');
            if ($organisation === '' || $this->organisationService->isOrganisationAdmin($organisation, $uid) === false) {
                return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            return $object;
        }

        // Personal — the per-object owner IDOR guard, unchanged.
        if ($object->getOwner() !== $uid) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        return $object;
    }//end ensureManageable()

    /**
     * Resolve the requested scope from the request (body `scope` / query `scope`).
     *
     * Anything other than `organisation` (including absent) resolves to `personal`
     * so the personal path is the safe default (design D1/D5).
     *
     * @return string The requested scope (`personal`|`organisation`).
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function requestedScope(): string
    {
        $scope = strtolower(trim((string) $this->request->getParam('scope', self::SCOPE_PERSONAL)));
        if ($scope === self::SCOPE_ORGANISATION) {
            return self::SCOPE_ORGANISATION;
        }

        return self::SCOPE_PERSONAL;
    }//end requestedScope()

    /**
     * Resolve a stored credential's scope from its property bag (absent ⇒ personal).
     *
     * @param array<string, mixed> $data The credential's property bag.
     *
     * @return string The scope (`personal`|`organisation`).
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function scopeOf(array $data): string
    {
        if ((string) ($data['scope'] ?? self::SCOPE_PERSONAL) === self::SCOPE_ORGANISATION) {
            return self::SCOPE_ORGANISATION;
        }

        return self::SCOPE_PERSONAL;
    }//end scopeOf()

    /**
     * Resolve the owning organisation for a create, defaulting to the active one.
     *
     * @param string $requested The `organisation` body param (may be empty).
     *
     * @return string The organisation UUID, or '' when none can be resolved.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function resolveOrganisation(string $requested): string
    {
        $organisation = trim($requested);
        if ($organisation !== '') {
            return $organisation;
        }

        return (string) ($this->organisationService->getActiveOrganisation()?->getUuid() ?? '');
    }//end resolveOrganisation()

    /**
     * Resolve + admin-gate the owning organisation for an organisation-scoped create.
     *
     * The `organisation` defaults to the caller's active organisation when omitted;
     * the caller MUST be an administrator of it (or a Nextcloud admin) — design D4.
     * This is the authz half of an organisation create and deliberately stays in the
     * controller: the broker's mint takes the ALREADY-GATED organisation and trusts it.
     *
     * @param string $uid The current user's UID.
     *
     * @return string|JSONResponse The gated organisation UUID, or a static error response.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function gatedOrganisation(string $uid): string | JSONResponse
    {
        $organisation = $this->resolveOrganisation(requested: (string) $this->request->getParam('organisation', ''));
        if ($organisation === '') {
            return new JSONResponse(['message' => 'Invalid credential request'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->organisationService->isOrganisationAdmin($organisation, $uid) === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        return $organisation;
    }//end gatedOrganisation()

    /**
     * Serialise a credential entity to a metadata-only array (never carries a secret).
     *
     * @param ObjectEntity $object The credential entity.
     *
     * @return array<string, mixed> The serialised metadata.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function serialise(ObjectEntity $object): array
    {
        return $object->jsonSerialize();
    }//end serialise()

    /**
     * Coerce a caller-supplied allowedApps value into a string array.
     *
     * @param mixed $value The raw param value.
     *
     * @return array<int, string> The sanitised app-id list.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function normaliseAllowedApps(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $apps = [];
        foreach ($value as $entry) {
            if (is_string($entry) === true && $entry !== '') {
                $apps[] = $entry;
            }
        }

        return $apps;
    }//end normaliseAllowedApps()

    /**
     * Coerce a caller-supplied headers value into a string=>string map.
     *
     * @param mixed $value The raw param value.
     *
     * @return array<string, string> The sanitised header map.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function normaliseHeaders(mixed $value): array
    {
        if (is_array($value) === false) {
            return [];
        }

        $headers = [];
        foreach ($value as $key => $entry) {
            if (is_string($key) === true && (is_string($entry) === true || is_numeric($entry) === true)) {
                $headers[$key] = (string) $entry;
            }
        }

        return $headers;
    }//end normaliseHeaders()

    /**
     * Coerce a caller-supplied body value into a raw string or null.
     *
     * @param mixed $value The raw param value.
     *
     * @return string|null The request body, or null.
     *
     * @spec openspec/specs/credential-broker/spec.md
     */
    private function normaliseBody(mixed $value): ?string
    {
        if (is_string($value) === true) {
            return $value;
        }

        if (is_array($value) === true) {
            $encoded = json_encode($value);
            if ($encoded !== false) {
                return $encoded;
            }
        }

        return null;
    }//end normaliseBody()
}//end class
