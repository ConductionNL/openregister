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
 * require a CSRF token. They carry no auth attribute, so Nextcloud's own
 * middleware already admits administrators only; a user an administrator
 * delegated the OpenRegister settings section to is not admitted, because
 * packs drive imports that write without RBAC (for example stackiq's CMDB
 * import). The body check repeats the admin test as defence in depth.
 *
 * Writes that address a pack by id take that id from the route only. Nextcloud
 * merges a JSON body over the URL parameters, so a body `id` (which in a pack
 * document is the slug) would otherwise replace the route id.
 *
 * Every error answers `{"message", "error", "details"?}` (ADR-050), built by
 * MigrationPackErrorResponder.
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

use OCA\OpenRegister\Exception\MigrationPackRefusedException;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponder;
use OCA\OpenRegister\Service\MigrationPack\MigrationPackRequestReader;
use OCA\OpenRegister\Service\MigrationPackService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

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
	public const MAX_PREVIEW_ROWS = MigrationPackRequestReader::MAX_PREVIEW_ROWS;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name.
	 * @param IRequest $request HTTP request.
	 * @param MigrationPackService $service Business logic.
	 * @param IUserSession $userSession Current-user session.
	 * @param IGroupManager $groupManager Group manager (admin check).
	 * @param LoggerInterface $logger Logger for the audit lines.
	 * @param MigrationPackRequestReader $requestReader Reads definitions, uploads and preview input.
	 * @param MigrationPackErrorResponder $errors Builds every error response.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MigrationPackService $service,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly MigrationPackRequestReader $requestReader,
		private readonly MigrationPackErrorResponder $errors,
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
	 * The refusal for a caller who may not change packs, or null for an administrator.
	 *
	 * @return JSONResponse|null 401 when nobody is signed in, 403 for a non-admin.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
	 */
	private function refuseNonAdmin(): ?JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->errors->notAuthenticated();
		}

		if ($this->groupManager->isAdmin($userId) === false) {
			return $this->errors->adminRequired();
		}

		return null;
	}//end refuseNonAdmin()

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
		if ($this->resolveUserId() === null) {
			return $this->errors->notAuthenticated();
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
		if ($this->resolveUserId() === null) {
			return $this->errors->notAuthenticated();
		}

		try {
			$pack = $this->service->find(id: $id);
		} catch (DoesNotExistException $e) {
			return $this->errors->notFound();
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
		if ($this->resolveUserId() === null) {
			return $this->errors->notAuthenticated();
		}

		try {
			$pack = $this->service->find(id: $id);
		} catch (DoesNotExistException $e) {
			return $this->errors->notFound();
		}

		$json = json_encode($pack->getDefinitionArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$filename = $pack->getPackSlug() . '.json';

		return new DataDownloadResponse($json, $filename, 'application/json');
	}//end export()

	/**
	 * Import a migration pack definition from an uploaded JSON file. Admin only.
	 * Upserts by the document's own `id` — re-importing an updated pack
	 * updates the existing row. The log line says whether the import created
	 * or replaced a pack, as reported by the write itself, and so does the
	 * status: 201 for a pack created, 200 for one replaced, so the caller can
	 * tell an administrator that a stored pack (possibly edited on this
	 * instance) was overwritten.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Imports write instance-wide pack definitions that drive RBAC-free imports, so only
	 *       Nextcloud administrators may call it; the body repeats the check.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function import(): JSONResponse {
		$refusal = $this->refuseNonAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$userId = (string)$this->resolveUserId();

		try {
			$definition = $this->requestReader->uploadedDefinition(request: $this->request);
			$result     = $this->service->importDefinition(definition: $definition, ownerUid: $userId);
		} catch (MigrationPackRefusedException $e) {
			return $this->errors->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->errors->unexpected(action: 'import', exception: $e);
		}

		$outcome = 'replaced';
		$status  = 200;
		if ($result->created === true) {
			$outcome = 'created';
			$status  = 201;
		}

		$pack = $result->pack;
		$this->logger->info(
			message: '[MigrationPacksController] Migration pack "{packSlug}" (id {packId}) imported by {userId}: {outcome}',
			context: [
				'packSlug' => $pack->getPackSlug(),
				'packId' => $pack->getId(),
				'userId' => $userId,
				'outcome' => $outcome,
				'builtin' => $pack->getBuiltin(),
			]
		);

		return new JSONResponse(data: $pack->jsonSerialize(), statusCode: $status);
	}//end import()

	/**
	 * Create a migration pack. Admin only.
	 *
	 * The body is either `{"definition": {...}}` or the pack document itself;
	 * see MigrationPackRequestReader::definition() for how the two differ.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Creates an instance-wide pack definition that drives RBAC-free imports, so only
	 *       Nextcloud administrators may call it; the body repeats the check.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function create(): JSONResponse {
		$refusal = $this->refuseNonAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$userId = (string)$this->resolveUserId();

		try {
			$definition = $this->requestReader->definition(request: $this->request);
			$pack       = $this->service->create(definition: $definition, ownerUid: $userId);
		} catch (MigrationPackRefusedException $e) {
			return $this->errors->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->errors->unexpected(action: 'create', exception: $e);
		}

		$this->logger->info(
			message: '[MigrationPacksController] Migration pack "{packSlug}" (id {packId}) created by {userId}',
			context: [
				'packSlug' => $pack->getPackSlug(),
				'packId' => $pack->getId(),
				'userId' => $userId,
				'builtin' => $pack->getBuiltin(),
			]
		);

		return new JSONResponse(data: $pack->jsonSerialize(), statusCode: 201);
	}//end create()

	/**
	 * Update an existing migration pack. Admin only.
	 *
	 * The body is either `{"definition": {...}}` or the pack document itself.
	 * The pack row is always the one named in the route. Renaming a built-in
	 * pack is refused with 422 `builtin-slug-locked`. An envelope with
	 * `expectedUpdatedAt` (the `updatedAt` the caller read) is refused with
	 * 409 `pack-changed` when the pack was saved since.
	 *
	 * @param int $id The migration pack id.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Changes an instance-wide pack definition that drives RBAC-free imports, so only
	 *       Nextcloud administrators may call it; the body repeats the check.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function update(int $id): JSONResponse {
		$refusal = $this->refuseNonAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$id = $this->requestReader->routeId(request: $this->request, dispatchedId: $id);

		try {
			$definition = $this->requestReader->definition(request: $this->request);
			$expected   = $this->requestReader->expectedUpdatedAt(request: $this->request);
			$pack       = $this->service->update(
				id: $id,
				definition: $definition,
				expectedUpdatedAt: $expected?->getTimestamp()
			);
		} catch (DoesNotExistException $e) {
			return $this->errors->notFound();
		} catch (MigrationPackRefusedException $e) {
			return $this->errors->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->errors->unexpected(action: 'update', exception: $e, context: ['id' => $id]);
		}

		$this->logger->info(
			message: '[MigrationPacksController] Migration pack "{packSlug}" (id {packId}) updated by {userId}',
			context: ['packSlug' => $pack->getPackSlug(), 'packId' => $id, 'userId' => $this->resolveUserId()]
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
	 * @auth admin-only Deletes an instance-wide pack definition that drives RBAC-free imports, so only
	 *       Nextcloud administrators may call it; the body repeats the check.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#migration-packs-must-be-manageable-via-admin-gated-rest-crud-with-json-file-importexport
	 */
	public function destroy(int $id): JSONResponse {
		$refusal = $this->refuseNonAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		$id = $this->requestReader->routeId(request: $this->request, dispatchedId: $id);

		try {
			$pack = $this->service->delete(id: $id);
		} catch (DoesNotExistException $e) {
			return $this->errors->notFound();
		} catch (Throwable $e) {
			return $this->errors->unexpected(action: 'delete', exception: $e, context: ['id' => $id]);
		}

		$this->logger->info(
			message: '[MigrationPacksController] Migration pack "{packSlug}" (id {packId}) deleted by {userId}',
			context: ['packSlug' => $pack->getPackSlug(), 'packId' => $id, 'userId' => $this->resolveUserId()]
		);

		return new JSONResponse(data: null, statusCode: 204);
	}//end destroy()

	/**
	 * Map sample rows through an unsaved pack definition. Admin only.
	 *
	 * Body: `{"definition": {...}, "rows": [{"<column>": "<value>"}, ...]}`
	 * with at most MAX_PREVIEW_ROWS rows and at most
	 * MigrationPackRequestReader::MAX_PREVIEW_BYTES in all. Returns
	 * `{"rows": [{"rowNumber", "data", "errors"}]}` from the same mapping
	 * engine an import uses. Nothing is stored.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Runs the mapping engine over caller-supplied input for the pack editor, which only
	 *       Nextcloud administrators use; the body repeats the check.
	 *
	 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-a-pack-must-be-testable-against-sample-rows-without-saving-it
	 */
	public function preview(): JSONResponse {
		$refusal = $this->refuseNonAdmin();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$input   = $this->requestReader->previewInput(request: $this->request);
			$results = $this->service->previewRows(definition: $input['definition'], rows: $input['rows']);
		} catch (MigrationPackRefusedException $e) {
			return $this->errors->refused(exception: $e);
		} catch (Throwable $e) {
			return $this->errors->unexpected(action: 'preview', exception: $e);
		}

		return new JSONResponse(data: ['rows' => $results]);
	}//end preview()
}//end class
