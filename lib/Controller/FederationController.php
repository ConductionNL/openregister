<?php

/**
 * OpenRegister FederationController.
 *
 * The serving side of OpenRegister federation: a remote Nextcloud instance that
 * holds a scoped share token reads (and, on read-write shares, writes) the
 * shared objects here. Endpoints are `#[PublicPage]` by design — the caller is
 * another server, not a local session, and the bearer share token in the URL is
 * the sole credential. Each request is scoped to exactly what the share grants
 * (register / schema / object / query), the sharing organisation, and — for
 * register/schema/query shares — only objects that are not marked confidential.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Token-scoped federation serving endpoints (read; write-through in a later phase).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The count went one over the
 * threshold when `IThrottler` was injected to close the unthrottled-share-token
 * exposure (ADR-082). Each dependency here is one distinct capability the
 * endpoints genuinely need — share persistence, object read/write, share
 * management, brute-force accounting, logging — and the honest ways to get back
 * under the limit are worse than the warning: a facade would hide which
 * security boundary each handler crosses, and dropping the throttler would
 * restore an exposure. Suppressed with that trade stated rather than silently
 * baselined.
 */
class FederationController extends Controller {

	/**
	 * Brute-force throttler action name for failed share-token presentations.
	 *
	 * Shared by every endpoint on this controller so a caller cannot spread
	 * guesses across the six entry points to stay under the per-action ceiling.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'openregister_federation_share_token';

	/**
	 * Confidentiality values treated as public (servable through a schema share).
	 *
	 * The empty string is present deliberately: an object that never had a
	 * confidentiality set is public. That is also why reading the WRONG
	 * property name fails OPEN rather than closed — see CONFIDENTIALITY_KEYS.
	 *
	 * @var string[]
	 */
	private const PUBLIC_CONFIDENTIALITY = ['', 'openbaar', 'public', 'open'];

	/**
	 * Every property name under which this one concept is stored.
	 *
	 * One concept, three spellings, written by three different producers:
	 *
	 *   - `confidentiality`              — what this controller has always read;
	 *   - `confidentialityLevel`         — what the ZGW migration mapping pack
	 *                                      writes (SeedZgwZakenMigrationPack maps
	 *                                      `/vertrouwelijkheidaanduiding` to it);
	 *   - `vertrouwelijkheidaanduiding`  — the ZGW/GGM schema property itself.
	 *
	 * Reading only the first is not a cosmetic miss. `?? ''` yields the empty
	 * string for an object that stores its level under either of the other two,
	 * and the empty string is in PUBLIC_CONFIDENTIALITY above — so an object
	 * marked `zeer_geheim` under a name this guard did not read was served as
	 * public. The guard failed OPEN on a vocabulary mismatch.
	 *
	 * Ordered most- to least-canonical; the first key actually present wins.
	 * Adding a spelling here is safe, removing one is not.
	 *
	 * @var string[]
	 */
	private const CONFIDENTIALITY_KEYS = [
		'confidentiality',
		'confidentialityLevel',
		'vertrouwelijkheidaanduiding',
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Current request.
	 * @param FederatedShareMapper $shareMapper Federated-share persistence.
	 * @param ObjectService $objectService Object read service.
	 * @param FederationShareService $shareService Share management (list/create/revoke).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FederatedShareMapper $shareMapper,
		private readonly ObjectService $objectService,
		private readonly FederationShareService $shareService,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List the active organisation's federated shares.
	 *
	 * @no-admin-idor-exempt Tenancy is enforced in the query, not in this body:
	 *       `FederationShareService::listShares()` reads through
	 *       `FederatedShareMapper::findAll()`, which applies
	 *       `MultiTenancyTrait::applyOrganisationFilter()` and fails CLOSED
	 *       (`1 = 0`) when the session has no active organisation. There is no
	 *       caller-supplied object reference here to substitute, and a guard
	 *       repeated in the controller would only be a second, weaker copy of the
	 *       one that actually runs. Pinned by
	 *       tests/Unit/Db/FederatedShareMapperTenancyTest.php, which goes red if
	 *       that filter call is removed.
	 *
	 * @return JSONResponse `{ results, total }`.
	 */
	#[NoAdminRequired]
	public function shares(): JSONResponse {
		$direction = $this->request->getParam('direction');
		if ($direction !== null) {
			$direction = (string)$direction;
		}

		$shares = $this->shareService->listShares(direction: $direction);

		return new JSONResponse(data: ['results' => $shares, 'total' => count($shares)]);
	}//end shares()

	/**
	 * Create an outgoing federated share and return it (with its token).
	 *
	 * @return JSONResponse The created share, or a validation error.
	 */
	#[NoAdminRequired]
	public function createShare(): JSONResponse {
		$params = [
			'scope' => $this->request->getParam('scope', 'schema'),
			'register' => $this->request->getParam('register'),
			'schema' => $this->request->getParam('schema'),
			'objectUri' => $this->request->getParam('objectUri'),
			'queryFilter' => $this->request->getParam('queryFilter'),
			'permissions' => $this->request->getParam('permissions', 'read'),
			'sharedWith' => $this->request->getParam('sharedWith'),
			'remoteInstanceUrl' => $this->request->getParam('remoteInstanceUrl'),
		];

		try {
			$share = $this->shareService->createOutgoingShare(params: $params);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] create share failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not create share'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $share, statusCode: Http::STATUS_CREATED);
	}//end createShare()

	/**
	 * Revoke a federated share.
	 *
	 * @param int $id The share id.
	 *
	 * @return JSONResponse The revoked share, or an error.
	 *
	 * @no-admin-idor-exempt The id IS caller-supplied, and it is checked — one
	 *       layer down. `FederationShareService::setStatus()` writes through
	 *       `FederatedShareMapper::updateFromArray()`, which loads the row with
	 *       `find()` FIRST, and `find()` applies
	 *       `MultiTenancyTrait::applyOrganisationFilter()`. Another
	 *       organisation's id therefore raises `DoesNotExistException` and is
	 *       answered 404, deliberately rather than 403: a 403 would confirm the
	 *       id exists and turn this endpoint into an existence oracle for
	 *       another tenant's shares. Pinned by
	 *       tests/Unit/Db/FederatedShareMapperTenancyTest.php.
	 */
	#[NoAdminRequired]
	public function revokeShare(int $id): JSONResponse {
		try {
			$share = $this->shareService->setStatus(id: $id, status: 'revoked');
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] revoke share failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not revoke share'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $share);
	}//end revokeShare()

	/**
	 * Serve the objects a share grants (paginated).
	 *
	 * @param string $shareToken The scoped bearer share token.
	 *
	 * @return JSONResponse `{ results, total }` or an error.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function objects(string $shareToken): JSONResponse {
		$share = $this->resolveAcceptedShare(shareToken: $shareToken);
		if ($share === null) {
			return new JSONResponse(data: ['error' => 'Invalid, unaccepted or revoked share'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$config = $this->buildScopeConfig(share: $share);
		$config['limit'] = (int)$this->request->getParam('_limit', 50);
		$config['offset'] = (int)$this->request->getParam('_offset', 0);

		$search = $this->request->getParam('_search');
		if ($search !== null && $search !== '') {
			$config['search'] = (string)$search;
		}

		try {
			$this->setServeContext(share: $share);
			$objects = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] serve objects failed: ' . get_class($e) . ': ' . $e->getMessage()
				. ' @ ' . $e->getFile() . ':' . $e->getLine());
			return new JSONResponse(data: ['error' => 'Could not read shared objects'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$objects = $this->normalize(objects: $objects);
		$objects = $this->applyShareVisibility(objects: $objects, share: $share);

		return new JSONResponse(data: ['results' => $objects, 'total' => count($objects)]);
	}//end objects()

	/**
	 * Serve a single shared object by id/uuid.
	 *
	 * @param string $shareToken The scoped bearer share token.
	 * @param string $id The object id or uuid.
	 *
	 * @return JSONResponse The object or an error.
	 *
	 * @spec openspec/changes/federation-scope-enforcement/specs/federation-scope-enforcement/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function object(string $shareToken, string $id): JSONResponse {
		$share = $this->resolveAcceptedShare(shareToken: $shareToken);
		if ($share === null) {
			return new JSONResponse(data: ['error' => 'Invalid, unaccepted or revoked share'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->scopeCoversObject(share: $share, id: $id) === false) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		try {
			$entity = $this->objectService->find(
				id: $id,
				register: $share->getRegister(),
				schema: $share->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] serve object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not read shared object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($entity === null) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$visible = $this->applyShareVisibility(objects: [$entity->jsonSerialize()], share: $share);
		if (count($visible) === 0) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $visible[0]);
	}//end object()

	/**
	 * Create an object in a shared register/schema (read-write shares only).
	 *
	 * @param string $shareToken The scoped bearer share token.
	 *
	 * @return JSONResponse The created object, or an error.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function createObject(string $shareToken): JSONResponse {
		$share = $this->resolveWritableShare(shareToken: $shareToken);
		if ($share === null) {
			return new JSONResponse(data: ['error' => 'Invalid share or read-only'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$data = (array)$this->request->getParams();
		unset($data['shareToken'], $data['_route']);
		// Pin the object to the sharing organisation — a federated writer can
		// never plant an object into another organisation.
		$data['@self'] = (($data['@self'] ?? []) + ['organisation' => $share->getOrganisation()]);

		try {
			$saved = $this->objectService->saveObject(
				object: $data,
				register: $share->getRegister(),
				schema: $share->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] create object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not create object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $saved->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end createObject()

	/**
	 * Update a shared object (read-write shares only).
	 *
	 * @param string $shareToken The scoped bearer share token.
	 * @param string $id The object id/uuid.
	 *
	 * @return JSONResponse The updated object, or an error.
	 *
	 * @spec openspec/changes/federation-scope-enforcement/specs/federation-scope-enforcement/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function updateObject(string $shareToken, string $id): JSONResponse {
		$share = $this->resolveWritableShare(shareToken: $shareToken);
		if ($share === null) {
			return new JSONResponse(data: ['error' => 'Invalid share or read-only'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->scopeCoversObject(share: $share, id: $id) === false) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$data = (array)$this->request->getParams();
		unset($data['shareToken'], $data['id'], $data['_route']);
		$data['@self'] = (($data['@self'] ?? []) + ['organisation' => $share->getOrganisation()]);

		try {
			$saved = $this->objectService->saveObject(
				object: $data,
				register: $share->getRegister(),
				schema: $share->getSchema(),
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] update object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not update object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $saved->jsonSerialize());
	}//end updateObject()

	/**
	 * Delete a shared object (read-write shares only).
	 *
	 * @param string $shareToken The scoped bearer share token.
	 * @param string $id The object id/uuid.
	 *
	 * @return JSONResponse Success, or an error.
	 *
	 * @spec openspec/changes/federation-scope-enforcement/specs/federation-scope-enforcement/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function deleteObject(string $shareToken, string $id): JSONResponse {
		$share = $this->resolveWritableShare(shareToken: $shareToken);
		if ($share === null) {
			return new JSONResponse(data: ['error' => 'Invalid share or read-only'], statusCode: Http::STATUS_FORBIDDEN);
		}

		if ($this->scopeCoversObject(share: $share, id: $id) === false) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		try {
			$ok = $this->objectService->deleteObject(
				uuid: $id,
				register: $share->getRegister(),
				schema: $share->getSchema(),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] delete object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not delete object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: ['deleted' => $ok]);
	}//end deleteObject()

	/**
	 * Resolve a share that grants write access (accepted + read-write), or null.
	 *
	 * @param string $shareToken The scoped bearer share token.
	 *
	 * @return FederatedShare|null The writable share, else null.
	 */
	private function resolveWritableShare(string $shareToken): ?FederatedShare {
		$share = $this->resolveAcceptedShare(shareToken: $shareToken);
		if ($share === null || $share->getPermissions() !== 'read-write') {
			return null;
		}

		return $share;
	}//end resolveWritableShare()

	/**
	 * Whether a share's scope actually covers the single object being addressed.
	 *
	 * An OBJECT-scope share grants exactly one object — the one the sharer
	 * picked — and that is what the collection endpoint serves, because
	 * `buildScopeConfig()` pins `filters['uuid']` to the share's `objectUri`.
	 * The single-object endpoints took `{id}` straight from the URL and never
	 * compared it to the grant, so the item path was strictly wider than the
	 * list path that guards it: a token for one object read (and, on a
	 * read-write share, overwrote or deleted) ANY object in the same
	 * register/schema. `applyShareVisibility()` does not close that, because it
	 * deliberately skips the confidentiality filter for object scope — so the
	 * widened reach also reached objects the register/schema scopes may never
	 * serve.
	 *
	 * Non-object scopes are unchanged: their breadth IS the grant, and
	 * `applyShareVisibility()` remains the guard there.
	 *
	 * @param FederatedShare $share The share being served.
	 * @param string $id The object id/uuid taken from the URL.
	 *
	 * @return boolean Whether the share covers this object.
	 */
	private function scopeCoversObject(FederatedShare $share, string $id): bool {
		if ($share->getScope() !== 'object') {
			return true;
		}

		$granted = (string)$share->getObjectUri();
		if ($granted === '') {
			// An object-scope share that names no object grants nothing. Failing
			// closed here is the only safe reading of a malformed grant.
			return false;
		}

		return ($this->uuidFromUri(uri: $granted) === $id);
	}//end scopeCoversObject()

	/**
	 * Describe a share (scope, register/schema, permissions) so the receiving
	 * instance can provision a shadow schema bound to the federated source.
	 *
	 * @param string $shareToken The scoped bearer share token.
	 *
	 * @return JSONResponse The share descriptor or an error.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function meta(string $shareToken): JSONResponse {
		$share = $this->resolveAcceptedShare(shareToken: $shareToken);
		if ($share === null) {
			return new JSONResponse(data: ['error' => 'Invalid, unaccepted or revoked share'], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			data: [
				'scope' => $share->getScope(),
				'register' => $share->getRegister(),
				'schema' => $share->getSchema(),
				'permissions' => $share->getPermissions(),
			]
		);
	}//end meta()

	/**
	 * Resolve an outgoing, accepted share by token, or null.
	 *
	 * @param string $shareToken The scoped bearer share token.
	 *
	 * @return FederatedShare|null The share when valid + accepted, else null.
	 */
	private function resolveAcceptedShare(string $shareToken): ?FederatedShare {
		if ($shareToken === '') {
			$this->registerFailedTokenAttempt();
			return null;
		}

		try {
			$share = $this->shareMapper->findByToken(shareToken: $shareToken);
		} catch (DoesNotExistException $e) {
			$this->registerFailedTokenAttempt();
			return null;
		} catch (Throwable $e) {
			$this->logger->warning('[Federation] token lookup failed: ' . $e->getMessage());
			$this->registerFailedTokenAttempt();
			return null;
		}

		// Only outgoing shares are served here, and only once accepted.
		if ($share->getDirection() !== 'outgoing' || $share->getStatus() === 'revoked' || $share->getStatus() === 'declined') {
			$this->registerFailedTokenAttempt();
			return null;
		}

		return $share;
	}//end resolveAcceptedShare()

	/**
	 * Record a failed share-token presentation with the brute-force throttler.
	 *
	 * Every federation endpoint is `#[PublicPage]` and the share token is the
	 * ONLY credential, so an unthrottled endpoint lets a caller guess tokens at
	 * line rate — and three of these endpoints WRITE (`createObject`,
	 * `updateObject`, `deleteObject`).
	 *
	 * This sits in the shared resolver rather than in each of the six call
	 * sites deliberately: a guard added per-endpoint is a guard that the
	 * seventh endpoint forgets. It also registers the attempt where the failure
	 * is actually known, rather than relying on the caller to remember to
	 * throttle its own response.
	 *
	 * `sleepDelayOrThrowOnMax()` is NOT called here, and that is only safe
	 * because every endpoint carries `#[BruteForceProtection]`. The framework's
	 * BruteForceMiddleware calls it on the way IN, per request, using the
	 * attribute's action. Registering without that attribute would write a
	 * counter nobody ever reads — an attempt log, not a control. (That is
	 * exactly what the first draft of this change did.)
	 *
	 * Enforcing on the way in rather than here also keeps a legitimate peer
	 * holding a valid token from being delayed by another peer's failures:
	 * the delay is keyed to the failing address.
	 *
	 * @return void
	 */
	private function registerFailedTokenAttempt(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (Throwable $e) {
			// Never let throttler bookkeeping turn a 404 into a 500.
			$this->logger->warning('[Federation] throttler registerAttempt failed: ' . $e->getMessage());
		}
	}//end registerFailedTokenAttempt()

	/**
	 * Build the ObjectService findAll config for a share's scope.
	 *
	 * @param FederatedShare $share The share being served.
	 *
	 * @return array<string, mixed> The findAll config (filters + scope narrowing).
	 */
	private function buildScopeConfig(FederatedShare $share): array {
		/*
		 * Register/schema are set as ObjectService CONTEXT (setServeContext), not
		 * as domain filters: a magic table's system columns are underscore-prefixed
		 * (_register/_schema/_organisation), so they are not valid domain filters.
		 * The organisation + confidentiality guards run in PHP (applyShareVisibility).
		 */

		$filters = [];

		if ($share->getScope() === 'object' && $share->getObjectUri() !== null && $share->getObjectUri() !== '') {
			// The objectUri stores the object uuid for an object-scope share.
			$filters['uuid'] = $this->uuidFromUri(uri: $share->getObjectUri());
		}

		if ($share->getScope() === 'query' && is_array($share->getQueryFilter()) === true) {
			$filters = array_merge($filters, $share->getQueryFilter());
		}

		return ['filters' => $filters];
	}//end buildScopeConfig()

	/**
	 * Set the register/schema context on ObjectService for a share.
	 *
	 * `setRegister()` / `setSchema()` resolve a slug or uuid through the
	 * mappers, so a share naming a register or schema that has since been
	 * deleted throws rather than returning null. The only caller wraps this in
	 * its own try/catch; declaring it here so that is a documented contract
	 * rather than an accident of where the call happens to sit.
	 *
	 * @param FederatedShare $share The share being served.
	 *
	 * @return void
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the share names a register or schema that no longer exists.
	 */
	private function setServeContext(FederatedShare $share): void {
		if ($share->getRegister() !== null && $share->getRegister() !== '') {
			$this->objectService->setRegister(register: $share->getRegister());
		}

		if ($share->getSchema() !== null && $share->getSchema() !== '') {
			$this->objectService->setSchema(schema: $share->getSchema());
		}
	}//end setServeContext()

	/**
	 * Normalise a findAll result to plain arrays (entities → jsonSerialize).
	 *
	 * @param array<int, mixed> $objects The raw findAll result.
	 *
	 * @return array<int, array<string, mixed>> The rendered arrays.
	 */
	private function normalize(array $objects): array {
		return array_map(
			static function ($object) {
				if (is_array($object) === true) {
					return $object;
				}

				if ($object instanceof \JsonSerializable) {
					return (array)$object->jsonSerialize();
				}

				return (array)$object;
			},
			$objects
		);
	}//end normalize()

	/**
	 * Enforce confidentiality visibility for the share scope.
	 *
	 * Object-scope shares serve exactly the granted object (the sharer chose it).
	 * Register/schema/query shares never leak an object marked confidential.
	 *
	 * @param array<int, array<string, mixed>> $objects The rendered objects.
	 * @param FederatedShare $share The share being served.
	 *
	 * @return array<int, array<string, mixed>> The visible objects.
	 */
	private function applyShareVisibility(array $objects, FederatedShare $share): array {
		$org = $share->getOrganisation();

		return array_values(
			array_filter(
				$objects,
				function (array $object) use ($share, $org) {
					// Organisation guard: never serve another org's objects.
					if ($org !== null && $org !== '') {
						$self = ($object['@self'] ?? []);
						$objOrg = (string)($self['organisation'] ?? $object['organisation'] ?? '');
						if ($objOrg !== $org) {
							return false;
						}
					}

					// Confidentiality guard for non-object scopes (object shares
					// serve exactly the one object the sharer chose).
					if ($share->getScope() !== 'object') {
						$confidentiality = $this->readConfidentiality(object: $object);
						if (in_array($confidentiality, self::PUBLIC_CONFIDENTIALITY, true) === false) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end applyShareVisibility()

	/**
	 * Read an object's confidentiality under any of its known property names.
	 *
	 * Returns the lowercased value of the FIRST key in CONFIDENTIALITY_KEYS that
	 * is present and non-empty, or '' when the object genuinely carries none.
	 *
	 * "Present and non-empty", not merely present: a schema sync can add an
	 * empty column for a property before anything writes to it, and an empty
	 * `confidentiality` sitting in front of a populated `confidentialityLevel`
	 * would reinstate exactly the fail-open this method exists to close.
	 *
	 * @param array<string, mixed> $object The rendered object.
	 *
	 * @return string Lowercased confidentiality, or '' when none is set.
	 *
	 * @spec openspec/specs/federation/spec.md
	 */
	private function readConfidentiality(array $object): string {
		foreach (self::CONFIDENTIALITY_KEYS as $key) {
			$value = strtolower(trim((string)($object[$key] ?? '')));
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end readConfidentiality()

	/**
	 * Extract the trailing uuid from a canonical object uri (or return it as-is).
	 *
	 * @param string $uri The object uri or uuid.
	 *
	 * @return string The uuid.
	 */
	private function uuidFromUri(string $uri): string {
		$parts = explode('/', rtrim($uri, '/'));
		return (string)end($parts);
	}//end uuidFromUri()
}//end class
