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

use OCA\OpenRegister\Controller\Support\FederationControllerServices;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Federation\CreatorPropertyFilter;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\FederationShareServiceInterface;
use OCA\OpenRegister\Service\Federation\PlainQueryMatch;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfacesInterface;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;

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
	public const THROTTLE_ACTION = ThrottledSurfacesInterface::FEDERATION_SHARE_SURFACE;

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
	 * Federated-share persistence.
	 *
	 * @var FederatedShareMapperInterface
	 */
	private readonly FederatedShareMapperInterface $shareMapper;

	/**
	 * Object read service.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Share management (list/create/revoke).
	 *
	 * @var FederationShareServiceInterface
	 */
	private readonly FederationShareServiceInterface $shareService;

	/**
	 * Brute-force throttler for rejected tokens.
	 *
	 * @var IThrottler
	 */
	private readonly IThrottler $throttler;

	/**
	 * Keeps an object-scope share to the properties its creator may read.
	 *
	 * @var CreatorPropertyFilter
	 */
	private readonly CreatorPropertyFilter $creatorProperties;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Current request.
	 * @param FederationControllerServices $services The federation controller services.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		FederationControllerServices $services,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->shareMapper = $services->shareMapper;
		$this->objectService = $services->objectService;
		$this->shareService = $services->shareService;
		$this->throttler = $services->throttler;
		$this->creatorProperties = $services->creatorProperties;
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
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
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
	 * @return JSONResponse The created share, a validation error, or a 403 when the caller does not hold the rights it grants.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
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
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
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
	 * @return JSONResponse The revoked share, or an error (403 when the caller does not hold the rights the share grants).
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
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	#[NoAdminRequired]
	public function revokeShare(int $id): JSONResponse {
		try {
			$share = $this->shareService->setStatus(id: $id, status: 'revoked');
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
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
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
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

		try {
			$visible = $this->reachableObject(share: $share, id: $id);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] serve object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not read shared object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($visible === null) {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $visible);
	}//end object()

	/**
	 * Create an object in a shared register/schema (read-write shares only).
	 *
	 * @param string $shareToken The scoped bearer share token.
	 *
	 * @return JSONResponse The created object, or an error.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
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

		if ($this->scopeAdmitsNewObject(share: $share, data: $data) === false) {
			return new JSONResponse(data: ['error' => 'This share does not cover the object to create'], statusCode: Http::STATUS_FORBIDDEN);
		}

		// 🔴 STRIP THE CALLER'S IDENTITY. This endpoint CREATES.
		//
		// `saveObject()` resolves its target from the payload:
		// `extractUuidAndNormalizeObject()` reads `@self.id` first, then `id`,
		// and treats a match as the uuid to UPDATE — PUT-semantically, so every
		// field the payload omits is NULLED.
		//
		// This path is `#[PublicPage]` and runs `_rbac: false`, so nothing
		// downstream would have refused it. A share grants the holder the right
		// to ADD objects; without this it also granted the right to overwrite
		// every existing object in the shared register/schema.
		//
		// The organisation pin below was already guarding the sibling half of
		// this — "a federated writer can never plant an object into another
		// organisation" — but `+` preserves the LEFT operand's keys, so a
		// caller-supplied `@self: {id: …}` survived the merge untouched.
		unset($data['id'], $data['uuid']);
		$self = (array)($data['@self'] ?? []);
		unset($self['id'], $self['uuid']);

		// Pin the object to the sharing organisation — a federated writer can
		// never plant an object into another organisation.
		//
		// ASSIGNED, not merged. This was `$self + ['organisation' => …]`, and
		// `+` keeps the LEFT operand's keys: a caller who supplied
		// `@self: {organisation: 'somebody-else'}` kept it, so the pin this
		// comment describes did not hold. The same `+` is what let a supplied
		// `@self.id` through. A pin has to overwrite, or it is a default.
		$self['organisation'] = $share->getOrganisation();
		$data['@self'] = $self;

		try {
			$saved = $this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $share->getRegister(), schema: $share->getSchema())
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] create object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not create object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $this->rendered(share: $share, object: $saved), statusCode: Http::STATUS_CREATED);
	}//end createObject()

	/**
	 * Update a shared object (read-write shares only).
	 *
	 * @param string $shareToken The scoped bearer share token.
	 * @param string $id The object id/uuid.
	 *
	 * @return JSONResponse The updated object, or an error.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function updateObject(string $shareToken, string $id): JSONResponse {
		$target = $this->writableTarget(shareToken: $shareToken, id: $id);
		if ($target instanceof JSONResponse) {
			return $target;
		}

		$share = $target['share'];

		$data = (array)$this->request->getParams();
		unset($data['shareToken'], $data['id'], $data['_route']);
		// Assigned, as on create: `+` would keep a caller-supplied organisation.
		$self = (array)($data['@self'] ?? []);
		$self['organisation'] = $share->getOrganisation();
		$data['@self'] = $self;

		try {
			$saved = $this->objectService->saveObject(
				object: $data,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $share->getRegister(), schema: $share->getSchema()),
					uuid: $target['uuid']
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] update object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not update object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $this->rendered(share: $share, object: $saved));
	}//end updateObject()

	/**
	 * Delete a shared object (read-write shares only).
	 *
	 * @param string $shareToken The scoped bearer share token.
	 * @param string $id The object id/uuid.
	 *
	 * @return JSONResponse Success, or an error.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function deleteObject(string $shareToken, string $id): JSONResponse {
		$target = $this->writableTarget(shareToken: $shareToken, id: $id);
		if ($target instanceof JSONResponse) {
			return $target;
		}

		$share = $target['share'];
		try {
			$ok = $this->objectService->deleteObject(
				deletion: new ObjectDeletion(
					uuid: $target['uuid'],
					target: new ObjectFindTarget(register: $share->getRegister(), schema: $share->getSchema()),
					reach: fn (string $uuid): bool => $uuid !== '' && $this->reachableEntity(share: $share, id: $uuid) !== null
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (ReferentialIntegrityException $e) {
			return new JSONResponse(data: ['error' => self::blockedDeleteMessage(refusal: $e)], statusCode: Http::STATUS_CONFLICT);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] delete object failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not delete object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: ['deleted' => $ok]);
	}//end deleteObject()

	/**
	 * Whether a share's scope covers an object about to be created.
	 *
	 * A schema or register share covers any new object in its table. An object share
	 * grants one existing object, so it covers none. A query share covers a new object
	 * only when each of its conditions is a plain value (or list of values) on a property
	 * the payload sets to that value; a condition this check cannot read, such as an
	 * operator, `_ids` or a search, covers nothing.
	 *
	 * @param FederatedShare       $share The share being served.
	 * @param array<string, mixed> $data  The payload to create.
	 *
	 * @return bool Whether the create falls inside the share.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	private function scopeAdmitsNewObject(FederatedShare $share, array $data): bool {
		if ($share->getScope() === 'object') {
			return false;
		}

		if ($share->getScope() !== 'query') {
			return true;
		}

		if (is_array($share->getQueryFilter()) === false) {
			return false;
		}

		return (new PlainQueryMatch())->admits(filters: $this->buildScopeConfig(share: $share)['filters'], data: $data);
	}//end scopeAdmitsNewObject()

	/**
	 * Why a federated delete was blocked by the objects it would change.
	 *
	 * @param ReferentialIntegrityException $refusal The refusal.
	 *
	 * @return string The message.
	 */
	private static function blockedDeleteMessage(ReferentialIntegrityException $refusal): string {
		foreach ($refusal->getAnalysis()->blockers as $blocker) {
			if (($blocker['reason'] ?? null) === 'out_of_reach') {
				return 'The delete would change or remove objects outside this share, so it was not carried out.';
			}
		}

		return 'Other objects refer to this object, so it was not deleted.';
	}//end blockedDeleteMessage()

	/**
	 * Resolve a share that grants write access (accepted, read-write, bound to an organisation), or null.
	 *
	 * @param string $shareToken The scoped bearer share token.
	 *
	 * @return FederatedShare|null The writable share, else null.
	 */
	private function resolveWritableShare(string $shareToken): ?FederatedShare {
		$share = $this->resolveAcceptedShare(shareToken: $shareToken);
		if ($share === null || $share->getPermissions() !== 'read-write' || (string)$share->getOrganisation() === '') {
			return null;
		}

		return $share;
	}//end resolveWritableShare()

	/**
	 * The writable share when it may write the object, else the response that refuses the write.
	 *
	 * @param string $shareToken The scoped bearer share token.
	 * @param string $id         The object id/uuid taken from the URL.
	 *
	 * @return array{share: FederatedShare, uuid: string}|JSONResponse The share and the uuid of the
	 *                                                                 object it may write, or a 403, 404 or 500 response.
	 */
	private function writableTarget(string $shareToken, string $id): array|JSONResponse {
		$share = $this->resolveWritableShare(shareToken: $shareToken);
		if ($share === null) {
			return new JSONResponse(data: ['error' => 'Invalid share or read-only'], statusCode: Http::STATUS_FORBIDDEN);
		}

		try {
			$target = $this->reachableEntity(share: $share, id: $id);
		} catch (Throwable $e) {
			$this->logger->error('[Federation] write target lookup failed: ' . $e->getMessage());
			return new JSONResponse(data: ['error' => 'Could not read shared object'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// The write names the object the guard checked by its uuid; one without a uuid is not written.
		$uuid = (string) ($target?->getUuid() ?? '');
		if ($uuid === '') {
			return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return ['share' => $share, 'uuid' => $uuid];
	}//end writableTarget()

	/**
	 * The object a single-object call addresses, rendered, when the share reaches it.
	 *
	 * Every single-object endpoint runs without access control, so this is its
	 * guard: the id must be inside the share's scope, the object must exist,
	 * and it must pass the same organisation and confidentiality filter the
	 * collection path applies. Anything else is null, which the endpoints
	 * answer with a 404 so a token cannot tell "outside the grant" from
	 * "does not exist".
	 *
	 * @param FederatedShare $share The share being served.
	 * @param string         $id    The object id/uuid taken from the URL.
	 *
	 * @return array<string, mixed>|null The rendered object, or null.
	 *
	 * @throws Throwable When the lookup itself fails.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function reachableObject(FederatedShare $share, string $id): ?array {
		$entity = $this->reachableEntity(share: $share, id: $id);
		if ($entity === null) {
			return null;
		}

		return ($this->applyShareVisibility(objects: [$entity], share: $share)[0] ?? null);
	}//end reachableObject()

	/**
	 * The object a single-object call addresses, when the share reaches it.
	 *
	 * The same guard as {@see reachableObject()}, answering with the stored
	 * object rather than its rendering, so a write can name it by its uuid.
	 *
	 * @param FederatedShare $share The share being served.
	 * @param string         $id    The object id/uuid taken from the URL.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @throws Throwable When the lookup itself fails.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function reachableEntity(FederatedShare $share, string $id): ?ObjectEntity {
		if ($this->scopeCoversObject(share: $share, id: $id) === false) {
			return null;
		}

		try {
			$entity = WriteCause::asLookup(fn () => $this->objectService->find(
				id: $id,
				options: new ObjectFindOptions(register: $share->getRegister(), schema: $share->getSchema()),
				_rbac: false,
				_multitenancy: false
			));
		} catch (DoesNotExistException $e) {
			return null;
		}

		if ($entity === null
			|| $this->queryCoversObject(share: $share, entity: $entity) === false
			|| $this->applyShareVisibility(objects: [$entity], share: $share) === []
		) {
			return null;
		}

		return $entity;
	}//end reachableEntity()

	/**
	 * Whether a query-scope share's filter selects the record being addressed.
	 *
	 * The collection path serves only what the share's query selects; the
	 * single-object paths ask the same query, unchanged, narrowed to this
	 * record's uuid through `ids`, and accept only a hit on that same uuid
	 * (`ids` also matches slugs). Other scopes have no query and are covered.
	 *
	 * `ids` replaces a share's own `_ids` condition in the search, so that list
	 * is asked first.
	 *
	 * @param FederatedShare $share  The share being served.
	 * @param ObjectEntity   $entity The record the URL id resolved to.
	 *
	 * @return boolean Whether the share's query selects this record.
	 *
	 * @throws Throwable When the lookup itself fails.
	 */
	private function queryCoversObject(FederatedShare $share, ObjectEntity $entity): bool {
		if ($share->getScope() !== 'query') {
			return true;
		}

		$uuid = (string)$entity->getUuid();
		$config = $this->buildScopeConfig(share: $share);
		if ($this->ownIdsCoverObject(filters: $config['filters'], entity: $entity) === false) {
			return false;
		}

		$config['ids'] = [$uuid];
		$config['limit'] = 10;

		$this->setServeContext(share: $share);

		foreach ($this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false) as $hit) {
			if ($uuid !== '' && $hit instanceof ObjectEntity === true && $hit->getUuid() === $uuid) {
				return true;
			}
		}

		return false;
	}//end queryCoversObject()

	/**
	 * Whether a query's own `_ids` condition lists the record, by uuid or slug as the search does.
	 *
	 * An empty condition selects by nothing, as on the collection path. A `_ids`
	 * that is not a list makes the collection search fail, so it covers nothing here.
	 *
	 * @param array<string, mixed> $filters The share's filters.
	 * @param ObjectEntity         $entity  The record being addressed.
	 *
	 * @return boolean Whether the condition admits the record.
	 */
	private function ownIdsCoverObject(array $filters, ObjectEntity $entity): bool {
		$listed = ($filters['_ids'] ?? null);
		if (empty($listed) === true) {
			return true;
		}

		if (is_array($listed) === false) {
			return false;
		}

		$identifiers = array_filter([(string)$entity->getUuid(), (string)$entity->getSlug()], static fn (string $id): bool => $id !== '');

		return array_intersect($identifiers, array_filter($listed, 'is_scalar')) !== [];
	}//end ownIdsCoverObject()

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

		// A share hands out only what its creator still holds, judged on every use.
		$refusal = $this->shareService->servingRefusal(share: $share);
		if ($refusal !== null) {
			$this->logger->warning(
				'[Federation] share not served: '.$refusal,
				['shareId' => $share->getId(), 'owner' => (string)$share->getOwner()]
			);
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
			// ObjectService takes these two as its context, which would move the search off the share's own target.
			unset($filters['register'], $filters['schema']);
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
	 * The records a share may serve, rendered.
	 *
	 * Organisation guard: a record is served only when its own organisation
	 * field equals the share's (both empty counts as equal, so a share without
	 * an organisation serves only records without one). The rendered
	 * `@self.organisation` is not used: `jsonSerialize()` lets a data
	 * `@self.organisation` override the record's, and a plain data
	 * `organisation` property is not record metadata at all. Anything that is
	 * not a record is not served.
	 *
	 * Confidentiality guard: register/schema/query shares never serve an object
	 * marked confidential; object-scope shares serve exactly the one object the
	 * sharer chose, with only the properties the sharer may read.
	 *
	 * @param array<int, mixed> $objects The records found.
	 * @param FederatedShare    $share   The share being served.
	 *
	 * @return array<int, array<string, mixed>> The visible records, rendered.
	 */
	private function applyShareVisibility(array $objects, FederatedShare $share): array {
		$org = (string)$share->getOrganisation();
		$visible = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity === false || (string)$object->getOrganisation() !== $org) {
				continue;
			}

			$rendered = $this->rendered(share: $share, object: $object);
			if ($share->getScope() === 'object') {
				$visible[] = $rendered;
				continue;
			}

			if (in_array($this->readConfidentiality(object: $rendered), self::PUBLIC_CONFIDENTIALITY, true) === false) {
				continue;
			}

			$visible[] = $rendered;
		}

		return $visible;
	}//end applyShareVisibility()

	/**
	 * An object as a share serves it: an object-scope share keeps to the properties its creator may read.
	 *
	 * @param FederatedShare $share  The share being served.
	 * @param ObjectEntity   $object The object.
	 *
	 * @return array<string, mixed> The rendered object.
	 */
	private function rendered(FederatedShare $share, ObjectEntity $object): array {
		$rendered = $object->jsonSerialize();
		if ($share->getScope() !== 'object') {
			return $rendered;
		}

		return $this->creatorProperties->readable(share: $share, object: $object, rendered: $rendered);
	}//end rendered()

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
