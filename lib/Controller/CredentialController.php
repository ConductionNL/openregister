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
 *
 * Every endpoint is `#[NoAdminRequired]` with a per-object owner IDOR guard (ADR-005);
 * identity comes only from `IUserSession` and, for the broker call, the app id comes
 * ONLY from the verified signed token — never from a body field. The stored secret is
 * NEVER returned in any response; client errors are static and generic.
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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use DateTimeImmutable;
use Throwable;

/**
 * HTTP controller for owner-scoped credentials and the constrained broker call.
 */
class CredentialController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                    $appName         App name (injected by NC).
     * @param IRequest                  $request         Current request.
     * @param IUserSession              $userSession     Current session (owner identity).
     * @param IGroupManager             $groupManager    Group manager (admin check for app registration).
     * @param ObjectService             $objectService   OR object CRUD for credential metadata.
     * @param CredentialStore           $credentialStore Secret store leaf (vault).
     * @param ProviderCatalogue         $catalogue       Read-only provider catalogue (validation).
     * @param CredentialBrokerService   $broker          The guarded outbound broker.
     * @param CredentialAppTokenService $tokenService    Per-app signing-secret registry + token verify.
     *
     * @return void
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
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * GET /api/credentials — list the caller's own credential metadata.
     *
     * @return JSONResponse The caller's credentials (metadata only; never a secret).
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->currentUid();
        if ($uid === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
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
            if (($data['@self']['owner'] ?? null) === $uid) {
                $own[] = $data;
            }
        }

        return new JSONResponse(['results' => $own]);
    }//end index()

    /**
     * GET /api/credentials/providers — list the read-only provider catalogue (id + title only).
     *
     * Powers the settings-UI provider picker. Exposes ONLY the identifier and title — never a
     * secret (there is none in the catalogue) and never the allow-rules (an internal guardrail).
     *
     * @return JSONResponse `{results: Array<{identifier, title}>}`.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
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
     * Body: `{name, provider, allowedApps?, secret?}`. The secret (if given) is written
     * straight to the vault under the new object UUID — never persisted to the OR object.
     *
     * @return JSONResponse The created credential metadata, or a static error.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
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

        if ($name === '' || $this->catalogue->get($provider) === null) {
            return new JSONResponse(['message' => 'Invalid credential request'], Http::STATUS_BAD_REQUEST);
        }

        $data = [
            'name'        => $name,
            'provider'    => $provider,
            'owner'       => $uid,
            'allowedApps' => $allowed,
            'createdAt'   => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        try {
            $saved = $this->objectService->saveObject(
                object: $data,
                register: CredentialBrokerService::REGISTER,
                schema: CredentialBrokerService::SCHEMA
            );
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Unable to create credential'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $uuid = (string) $saved->getUuid();
        if (is_string($secret) === true && $secret !== '') {
            $this->credentialStore->put($uuid, $secret);
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
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $uid      = $this->currentUid();
        $existing = $this->ensureOwned(id: $id, uid: $uid);
        if ($existing instanceof JSONResponse) {
            return $existing;
        }

        // Raw property bag (no `@self` envelope / `id`) — the update carries only
        // metadata, never a secret (there is none in the object).
        $data = $existing->getObject();

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
            $this->credentialStore->put($id, $secret);
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
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $uid      = $this->currentUid();
        $existing = $this->ensureOwned(id: $id, uid: $uid);
        if ($existing instanceof JSONResponse) {
            return $existing;
        }

        try {
            $this->objectService->deleteObject(
                uuid: $id,
                register: CredentialBrokerService::REGISTER,
                schema: CredentialBrokerService::SCHEMA
            );
        } catch (Throwable $e) {
            return new JSONResponse(['message' => 'Unable to delete credential'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->credentialStore->delete($id);

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
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#app-manifest-declares-provider-usage
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
     * @param string $id The credential UUID.
     *
     * @return JSONResponse `{status, headers, body}` from the upstream, or a static error.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function brokerRequest(string $id): JSONResponse
    {
        if ($this->currentUid() === null) {
            return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $token = $this->request->getHeader('X-Credential-Token');

        try {
            $claims = $this->tokenService->verify(token: $token);
            if ($claims['credentialId'] !== $id) {
                throw new CredentialAccessDeniedException(message: 'token/credential mismatch');
            }

            $result = $this->broker->request(
                credentialId: $id,
                appId: $claims['appId'],
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
    }//end brokerRequest()

    /**
     * Resolve the current user's id, or null when unauthenticated.
     *
     * @return string|null The current UID, or null.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    private function currentUid(): ?string
    {
        return $this->userSession->getUser()?->getUID();
    }//end currentUid()

    /**
     * Load a credential and enforce the per-object owner IDOR guard.
     *
     * @param string      $id  The credential UUID.
     * @param string|null $uid The current user's UID (null when unauthenticated).
     *
     * @return ObjectEntity|JSONResponse The owned entity, or a static error response.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
     */
    private function ensureOwned(string $id, ?string $uid): ObjectEntity | JSONResponse
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

        if ($object === null || $object->getOwner() !== $uid) {
            // Uniform 403 — never distinguish "missing" from "not yours".
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        return $object;
    }//end ensureOwned()

    /**
     * Serialise a credential entity to a metadata-only array (never carries a secret).
     *
     * @param ObjectEntity $object The credential entity.
     *
     * @return array<string, mixed> The serialised metadata.
     *
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
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
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#credential-metadata-schema
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
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
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
     * @spec openspec/changes/credential-broker/specs/credential-broker/spec.md#provider-catalogue-as-a-runtime-immutable-lib-file
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
