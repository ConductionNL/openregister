<?php

/**
 * OpenRegister Migration Packs Controller
 *
 * REST CRUD for MigrationPack rows plus JSON file import/export so packs can
 * be shared between instances. Reads (`index`/`show`/`export`) are available
 * to any authenticated user — the import endpoint's `packId` parameter needs
 * to resolve a pack for any user with manage-permission on the target
 * register, not just admins. Mutations (`create`/`update`/`destroy`/`import`)
 * and the `preview` dry run are restricted to Nextcloud administrators and
 * require a CSRF token. `#[AuthorizedAdminSetting]` keeps anyone outside the
 * OpenRegister admin section out at the middleware; the body check then
 * narrows that to Nextcloud administrators, because packs drive imports that
 * write without RBAC (for example stackiq's CMDB import).
 *
 * Writes that address a pack by id take that id from the route only. Nextcloud
 * merges a JSON body over the URL parameters, so a body `id` (which in a pack
 * document is the slug) would otherwise replace the route id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\BuiltinPackSlugLockedException;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * MigrationPacksController handles CRUD + JSON import/export for migration packs.
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
class MigrationPacksController extends Controller {
	/**
	 * The most sample rows one preview request may map.
	 *
	 * @var int
	 */
	public const MAX_PREVIEW_ROWS = 20;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param MigrationPackService $service Business logic.
	 * @param IUserSession $userSession Current-user session.
	 * @param IGroupManager $groupManager Group manager (admin check).
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Translator for error messages.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MigrationPackService $service,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Resolve the current user's uid, or null when anonymous.
	 *
	 * @return ?string
	 */
	private function resolveUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end resolveUserId()

	/**
	 * Whether the current user is a Nextcloud administrator.
	 *
	 * @return bool
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * Build a 401 response for anonymous callers.
	 *
	 * @return JSONResponse
	 */
	private function authRequiredResponse(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
	}//end authRequiredResponse()

	/**
	 * Build a 403 response for non-admin callers on an admin-only action.
	 *
	 * @return JSONResponse
	 */
	private function adminRequiredResponse(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Administrator privileges required'], statusCode: 403);
	}//end adminRequiredResponse()

	/**
	 * The parameters the router extracted from the URL, without the body.
	 *
	 * IRequest declares `urlParams` with `@property-read`; the isset() lets a
	 * request double that does not carry it fall back to no route values.
	 *
	 * @return array<string, string>
	 *
	 * @psalm-suppress NoInterfaceProperties `urlParams` is a documented read-only property of IRequest.
	 */
	private function routeParams(): array {
		if (isset($this->request->urlParams) === false) {
			return [];
		}

		return $this->request->urlParams;
	}//end routeParams()

	/**
	 * The pack row id from the route, falling back to the dispatched argument.
	 *
	 * The dispatched `$id` is read from the merged parameters, where a body
	 * `id` wins; a pack slug there is cast to 0. The route value does not
	 * have that problem.
	 *
	 * @param int $id The id the dispatcher passed in.
	 *
	 * @return int
	 */
	private function resolveRouteId(int $id): int {
		$routeId = (string)($this->routeParams()['id'] ?? '');
		if (ctype_digit($routeId) === true) {
			return (int)$routeId;
		}

		return $id;
	}//end resolveRouteId()

	/**
	 * Read the pack definition from the request body.
	 *
	 * Accepts the envelope `{"definition": {...}}`, taken as is, or the pack
	 * document as the whole body. In the second form, router keys (`_route`
	 * and other `_`-prefixed keys) are dropped, and so is an `id` that is only
	 * the route id merged in by Nextcloud rather than a slug from the body.
	 *
	 * @return array<string, mixed>
	 */
	private function definitionFromRequest(): array {
		$params = $this->request->getParams();
		if (is_array($params['definition'] ?? null) === true) {
			return $params['definition'];
		}

		foreach (array_keys($params) as $key) {
			if (str_starts_with((string)$key, '_') === true) {
				unset($params[$key]);
			}
		}

		$routeParams = $this->routeParams();
		if (array_key_exists('id', $routeParams) === true
			&& array_key_exists('id', $params) === true
			&& (string)$params['id'] === (string)$routeParams['id']
		) {
			unset($params['id']);
		}

		return $params;
	}//end definitionFromRequest()

	/**
	 * Build a 400 response for a malformed preview request.
	 *
	 * @param string $message Translated, human-readable reason.
	 * @param string $error Machine-readable error code.
	 *
	 * @return JSONResponse
	 */
	private function badRequestResponse(string $message, string $error): JSONResponse {
		return new JSONResponse(data: ['message' => $message, 'error' => $error], statusCode: 400);
	}//end badRequestResponse()

	/**
	 * List every migration pack. Any authenticated user may browse packs to
	 * pick one for an import request.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Migration packs are instance-wide reference assets, not
	 *   per-user objects: the spec's "Migration packs MUST be manageable via
	 *   admin-gated REST CRUD" requirement states that index/show/export are
	 *   "available to any authenticated user (packs are shared instance assets the
	 *   import flow must browse)" while create/update/destroy/import stay admin-only.
	 *   This method takes NO caller-supplied object id at all — it lists the whole
	 *   catalogue — so there is no object reference to scope to the caller. The
	 *   `owner` column records who authored a pack; it is not a visibility boundary
	 *   (the seeded `zgw-zaken-json` pack ships with `builtin: true` and no owner).
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		$packs = $this->service->findAll();
		$items = array_map(static fn ($pack) => $pack->jsonSerialize(), $packs);

		return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
	}//end index()

	/**
	 * Get a single migration pack.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt A migration pack is an instance-wide reference asset, not
	 *   a per-user object, and every authenticated user is entitled to read every
	 *   pack by spec ("index/show/export available to any authenticated user (packs
	 *   are shared instance assets the import flow must browse)"). There is no
	 *   tenancy or ownership boundary to scope $id against: a pack carries no
	 *   register, schema or organisation, and its payload is field-mapping
	 *   configuration rather than object data. Mutation (create/update/destroy/
	 *   import) IS admin-gated in this same controller.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$pack = $this->service->find(id: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
		}

		return new JSONResponse(data: $pack->jsonSerialize());
	}//end show()

	/**
	 * Download a migration pack's definition as a standalone JSON file, so it
	 * can be shared with (and imported into) another instance.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return JSONResponse|DataDownloadResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Same subject as show(): the downloaded payload is the
	 *   pack's own field-mapping definition — instance-wide reference configuration
	 *   with no register, schema, organisation or object data in it — and the spec
	 *   requires export to be "available to any authenticated user (packs are shared
	 *   instance assets the import flow must browse)" so packs can round-trip
	 *   between instances. There is no per-caller scope $id could be resolved under.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function export(int $id): JSONResponse|DataDownloadResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		try {
			$pack = $this->service->find(id: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
		}

		$json = json_encode($pack->getDefinitionArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$filename = $pack->getPackSlug() . '.json';

		return new DataDownloadResponse($json, $filename, 'application/json');
	}//end export()

	/**
	 * Import a migration pack definition from an uploaded JSON file. Admin only.
	 * Upserts by the document's own `id` — re-importing an updated pack
	 * updates the existing row.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function import(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequiredResponse();
		}

		$uploadedFile = $this->request->getUploadedFile('file');
		if (empty($uploadedFile['tmp_name']) === true) {
			return new JSONResponse(data: ['error' => 'No file uploaded'], statusCode: 400);
		}

		$raw = file_get_contents($uploadedFile['tmp_name']);
		$decoded = null;
		if ($raw !== false) {
			$decoded = json_decode($raw, true);
		}

		if (is_array($decoded) === false) {
			return new JSONResponse(data: ['error' => 'Uploaded file is not a valid JSON object'], statusCode: 400);
		}

		try {
			$pack = $this->service->importDefinition(definition: $decoded, ownerUid: $userId);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MigrationPacksController] Error importing migration pack: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Failed to import migration pack'], statusCode: 500);
		}

		return new JSONResponse(data: $pack->jsonSerialize(), statusCode: 201);
	}//end import()

	/**
	 * Create a migration pack. Admin only.
	 *
	 * The body is either `{"definition": {...}}` or the pack document itself.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function create(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequiredResponse();
		}

		$definition = $this->definitionFromRequest();

		try {
			$pack = $this->service->create(definition: $definition, ownerUid: $userId);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MigrationPacksController] Error creating migration pack: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Failed to create migration pack'], statusCode: 500);
		}

		return new JSONResponse(data: $pack->jsonSerialize(), statusCode: 201);
	}//end create()

	/**
	 * Update an existing migration pack. Admin only.
	 *
	 * The body is either `{"definition": {...}}` or the pack document itself.
	 * The pack row is always the one named in the route. Renaming a built-in
	 * pack is refused with 422 `builtin-slug-locked`.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function update(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequiredResponse();
		}

		$id = $this->resolveRouteId(id: $id);
		$definition = $this->definitionFromRequest();

		try {
			$pack = $this->service->update(id: $id, definition: $definition);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
		} catch (BuiltinPackSlugLockedException $e) {
			return new JSONResponse(
				data: [
					'message' => $this->l10n->t('The id of a built-in migration pack cannot be changed'),
					'error' => 'builtin-slug-locked',
				],
				statusCode: 422
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MigrationPacksController] Error updating migration pack: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Failed to update migration pack'], statusCode: 500);
		}

		$this->logger->info(
			message: '[MigrationPacksController] Migration pack "{packSlug}" (id {packId}) updated by {userId}',
			context: ['packSlug' => $pack->getPackSlug(), 'packId' => $id, 'userId' => $userId]
		);

		return new JSONResponse(data: $pack->jsonSerialize());
	}//end update()

	/**
	 * Delete a migration pack. Admin only. The pack row is always the one
	 * named in the route.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function destroy(int $id): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequiredResponse();
		}

		$id = $this->resolveRouteId(id: $id);

		try {
			$pack = $this->service->delete(id: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Migration pack not found'], statusCode: 404);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MigrationPacksController] Error deleting migration pack: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $id, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(data: ['error' => 'Failed to delete migration pack'], statusCode: 500);
		}

		$this->logger->info(
			message: '[MigrationPacksController] Migration pack "{packSlug}" (id {packId}) deleted by {userId}',
			context: ['packSlug' => $pack->getPackSlug(), 'packId' => $id, 'userId' => $userId]
		);

		return new JSONResponse(data: null, statusCode: 204);
	}//end destroy()

	/**
	 * Map sample rows through an unsaved pack definition. Admin only.
	 *
	 * Body: `{"definition": {...}, "rows": [{"<column>": "<value>"}, ...]}`
	 * with at most MAX_PREVIEW_ROWS rows. Returns
	 * `{"rows": [{"rowNumber", "data", "errors"}]}` from the same mapping
	 * engine an import uses. Nothing is stored.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-mapping-engine-must-apply-pack-transforms-per-row-and-never-leak-unresolved-references
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function preview(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authRequiredResponse();
		}

		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequiredResponse();
		}

		$definition = $this->request->getParam('definition');
		if (is_array($definition) === false) {
			return $this->badRequestResponse(
				message: $this->l10n->t('The request must contain a pack definition object'),
				error: 'invalid-request'
			);
		}

		$rows = $this->request->getParam('rows', []);
		if (is_array($rows) === false || array_is_list($rows) === false) {
			return $this->badRequestResponse(
				message: $this->l10n->t('Sample rows must be a list of objects'),
				error: 'invalid-request'
			);
		}

		if (count($rows) > self::MAX_PREVIEW_ROWS) {
			return $this->badRequestResponse(
				message: $this->l10n->t('A preview takes at most %s sample rows', [self::MAX_PREVIEW_ROWS]),
				error: 'too-many-rows'
			);
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				return $this->badRequestResponse(
					message: $this->l10n->t('Sample rows must be a list of objects'),
					error: 'invalid-request'
				);
			}
		}

		$errors = $this->service->validateDefinition(definition: $definition);
		if ($errors !== []) {
			return new JSONResponse(
				data: [
					'message' => $this->l10n->t('The pack definition is invalid'),
					'error' => 'invalid-pack',
					'details' => ['errors' => $errors],
				],
				statusCode: 422
			);
		}

		try {
			$results = $this->service->previewRows(definition: $definition, rows: $rows);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[MigrationPacksController] Error previewing migration pack: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'trace' => $e->getTraceAsString()]
			);
			return new JSONResponse(
				data: [
					'message' => $this->l10n->t('Failed to preview migration pack'),
					'error' => 'preview-failed',
				],
				statusCode: 500
			);
		}

		return new JSONResponse(data: ['rows' => $results]);
	}//end preview()
}//end class
