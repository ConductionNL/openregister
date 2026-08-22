<?php

/**
 * OpenRegister Bulk Operations Controller
 *
 * Controller for handling bulk operations on objects in the OpenRegister app.
 * Provides endpoints for bulk delete and save operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\BulkSaveOutcome;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Bulk operations controller for OpenRegister
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/data-import-export/spec.md
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class BulkController extends Controller {
	/**
	 * Constructor for the BulkController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param ObjectService $objectService The object service
	 * @param RegisterMapper $registerMapper Mapper for resolving registers (RBAC gates)
	 * @param SchemaMapper $schemaMapper Mapper for resolving schemas (RBAC gates)
	 * @param IUserSession $userSession User session for admin/manage checks
	 * @param IGroupManager $groupManager Group manager for admin/manage checks
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Check if the current user has 'manage' permission on a schema.
	 *
	 * Default-SECURE mirror of `SchemasController::checkSchemaManagePermission()`:
	 * a schema with no `manage` authorization rule can only be managed by
	 * administrators. When manage rules are present, membership of one of the
	 * listed groups grants permission (admins always pass). Deliberately NOT
	 * `PermissionHandler::hasPermission()`, which is default-OPEN for object
	 * data RBAC and therefore unsuitable for gating schema-definition writes.
	 *
	 * @param Schema $schema The schema to check manage permission for.
	 *
	 * @return bool True if the current user may manage this schema.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function checkSchemaManagePermission(Schema $schema): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		// Admins always pass.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$authorization = $schema->getAuthorization();

		// Default-secure: no manage rule defined → admin-only (already failed above).
		if (empty($authorization) === true || isset($authorization['manage']) === false) {
			return false;
		}

		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (\Throwable $e) {
			return false;
		}

		$manageRules = $authorization['manage'];
		foreach ($userGroups as $groupId) {
			foreach ($manageRules as $entry) {
				if (is_string($entry) === true && $entry === $groupId) {
					return true;
				}

				if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
					return true;
				}
			}
		}

		return false;
	}//end checkSchemaManagePermission()

	/**
	 * Check if the current user has 'manage' permission on a register.
	 *
	 * Default-SECURE: a register with no `manage` authorization rule can only
	 * be managed by administrators. When manage rules are present, membership
	 * of one of the listed groups grants permission (admins always pass).
	 * Mirrors `RegistersController::checkRegisterManagePermission()`.
	 *
	 * @param Register $register The register to check manage permission for.
	 *
	 * @return bool True if the current user may manage this register.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function checkRegisterManagePermission(Register $register): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		// Admins always pass.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		$authorization = $register->getAuthorization();

		// Default-secure: no manage rule defined → admin-only (already failed above).
		if (empty($authorization) === true || isset($authorization['manage']) === false) {
			return false;
		}

		try {
			$userGroups = $this->groupManager->getUserGroupIds($user);
		} catch (\Throwable $e) {
			return false;
		}

		$manageRules = $authorization['manage'];
		foreach ($userGroups as $groupId) {
			foreach ($manageRules as $entry) {
				if (is_string($entry) === true && $entry === $groupId) {
					return true;
				}

				if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
					return true;
				}
			}
		}

		return false;
	}//end checkRegisterManagePermission()

	/**
	 * Resolve register and schema slugs/IDs to numeric IDs.
	 *
	 * This method handles both slugs and numeric IDs by attempting to set them
	 * in the ObjectService, which will resolve slugs to IDs.
	 *
	 * @param string $register The register slug or ID
	 * @param string $schema The schema slug or ID
	 * @param ObjectService $objectService The object service
	 *
	 * @return array{register: int, schema: int} Resolved numeric IDs
	 *
	 * @throws RegisterNotFoundException If register not found
	 * @throws SchemaNotFoundException If schema not found
	 *
	 * @psalm-return   array{register: int, schema: int}
	 * @phpstan-return array{register: int, schema: int}
	 */
	private function resolveRegisterSchemaIds(string $register, string $schema, ObjectService $objectService): array {
		try {
			// Resolve register slug/ID to numeric ID.
			$objectService->setRegister(register: $register);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new RegisterNotFoundException(registerSlugOrId: $register, code: 404, previous: $e);
		}

		try {
			// Resolve schema slug/ID to numeric ID.
			$objectService->setSchema(schema: $schema);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			throw new SchemaNotFoundException(schemaSlugOrId: $schema, code: 404, previous: $e);
		}

		// Get resolved numeric IDs.
		$resolvedRegisterId = $objectService->getRegister();
		$resolvedSchemaId = $objectService->getSchema();

		// Reset ObjectService with resolved numeric IDs for consistency.
		$objectService->setRegister(register: (string)$resolvedRegisterId)->setSchema(schema: (string)$resolvedSchemaId);

		return [
			'register' => $resolvedRegisterId,
			'schema' => $resolvedSchemaId,
		];
	}//end resolveRegisterSchemaIds()

	/**
	 * Perform bulk delete operations on objects
	 *
	 * @param string $register The register identifier
	 * @param string $schema The schema identifier
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with bulk delete result
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function delete(string $register, string $schema): JSONResponse {
		try {
			// Resolve slugs to numeric IDs.
			try {
				$resolved = $this->resolveRegisterSchemaIds(
					register: $register,
					schema: $schema,
					objectService: $this->objectService
				);
			} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
			}

			// Get request data.
			$data = $this->request->getParams();
			$uuids = $data['uuids'] ?? [];

			// Validate input.
			if (empty($uuids) === true || is_array($uuids) === false) {
				return new JSONResponse(
					data: ['error' => 'Invalid input. "uuids" array is required.'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Set register and schema context using resolved IDs.
			$this->objectService->setRegister((string)$resolved['register']);
			$this->objectService->setSchema((string)$resolved['schema']);

			// Perform bulk delete operation with referential integrity enforcement per object.
			$result = $this->objectService->deleteObjects($uuids);
			$deletedUuids = $result['deleted_uuids'];
			$skippedUuids = $result['skipped_uuids'];
			$cascadeCount = $result['cascade_count'];

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Bulk delete operation completed successfully',
					'deleted_count' => count($deletedUuids),
					'deleted_uuids' => $deletedUuids,
					'requested_count' => count($uuids),
					'skipped_count' => count($skippedUuids),
					'skipped_uuids' => $skippedUuids,
					'cascade_count' => $cascadeCount,
					'total_affected' => count($deletedUuids) + $cascadeCount,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Bulk delete operation failed: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end delete()

	/**
	 * Write a resolved batch through whichever path the caller asked for.
	 *
	 * `stream` is opt-in and defaults to the existing behaviour, because neither
	 * path is universally better and the caller is the one who knows the payload.
	 *
	 * The default uses MagicMapper's ultraFastBulkSave: fastest writes, but it
	 * never consults the reference-validation cache, so rows that reference each
	 * other cost N×M database round-trips resolving them.
	 *
	 * Streaming puts each row through saveObject(), which engages that cache —
	 * repeated targets resolve from memory — and consumes the payload lazily. It
	 * also isolates failures per row rather than failing the whole call, which is
	 * why its `success` reflects the failed count instead of being a constant: a
	 * caller reading only the HTTP status would otherwise take a partial import
	 * for a complete one.
	 *
	 * Defaulting streaming on would silently slow down flat payloads, and
	 * choosing automatically would need a threshold nobody has measured — so it
	 * is a decision the caller makes rather than one inferred here.
	 *
	 * Both paths report a shortfall the same way — see {@see bulkSaveResponse()}.
	 * A row this endpoint refused is a row the caller still believes it stored,
	 * so neither path may answer `success: true` unless every submitted row is
	 * accounted for.
	 *
	 * @param array $objects Rows to write.
	 * @param int $register Resolved register id.
	 * @param int|null $schema Resolved schema id, or null for a mixed-schema batch.
	 * @param bool $stream Whether to use the row-at-a-time path.
	 * @param bool $partial Whether the caller opted into best-effort semantics.
	 *
	 * @return JSONResponse The batch outcome.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BulkSaveOutcome::from*() are named
	 *   constructors on a value object, not calls into a collaborator — there is
	 *   nothing here a test would want to substitute.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function writeBatch(
		array $objects,
		int $register,
		?int $schema,
		bool $stream,
		bool $partial,
	): JSONResponse {
		$requestedCount = count($objects);

		if ($stream === true) {
			$status = $this->objectService->saveObjectsStreaming(
				objects: $objects,
				register: $register,
				schema: $schema
			);

			$savedCount = ($status->getCreatedCount() + $status->getUpdatedCount());
			$accounted = ($savedCount + $status->getUnchangedCount());

			return $this->bulkSaveResponse(
				successMessage: 'Bulk save operation completed (streaming)',
				savedCount: $savedCount,
				requestedCount: $requestedCount,
				outcome: BulkSaveOutcome::fromBatchStatus(
					status: $status,
					requestedCount: $requestedCount,
					accountedCount: $accounted
				),
				extra: ['saved_objects' => $status->toArray()],
				partial: $partial
			);
		}

		$savedObjects = $this->objectService->saveObjects(
			objects: $objects,
			register: $register,
			schema: $schema,
			_rbac: true,
			_multitenancy: true,
			validation: true,
			events: false
		);

		$statistics = ($savedObjects['statistics'] ?? []);
		$savedCount = ((int)($statistics['saved'] ?? 0) + (int)($statistics['updated'] ?? 0));
		$accounted = ($savedCount + (int)($statistics['unchanged'] ?? 0));

		return $this->bulkSaveResponse(
			successMessage: 'Bulk save operation completed successfully',
			savedCount: $savedCount,
			requestedCount: $requestedCount,
			outcome: BulkSaveOutcome::fromBulkResult(
				bulkResult: $savedObjects,
				requestedCount: $requestedCount,
				accountedCount: $accounted
			),
			extra: ['saved_objects' => $savedObjects],
			partial: $partial
		);

	}//end writeBatch()

	/**
	 * Build the bulk-save response so a shortfall can never read as a success.
	 *
	 * The rule this enforces (issue #2778): `success` is true only when EVERY
	 * submitted object was written. It used to be the constant `true` on the
	 * non-streaming path, so a batch where 27 of 58 rows were refused for
	 * failing schema validation answered "completed successfully" and only
	 * `saved_count` — which the caller had to think to compare against its own
	 * request size — carried the loss. A caller that trusts `success` has
	 * already moved on by then, and `events: false` on this path means nothing
	 * downstream notices either.
	 *
	 * `success` is therefore unconditional and never softened by `partial`:
	 * making the flag mean "some rows landed" would re-create exactly the shape
	 * this fixes. What `partial` changes is only the HTTP status — an opted-in
	 * caller gets 200 with an honest `success: false` plus the failures, rather
	 * than a 422 its client library would raise on.
	 *
	 * 422 (not 500) matches the sibling `save()` catch for a unique-constraint
	 * collision: the request was understood, and the server refused the content
	 * of specific rows. Rows that DID write are not rolled back — this endpoint
	 * has never been transactional — which is why `saved_count` stays in the
	 * body of the failure response: it is what the caller must reconcile against.
	 *
	 * @param string $successMessage Message used when nothing failed.
	 * @param int $savedCount Rows created or updated.
	 * @param int $requestedCount Rows the caller submitted.
	 * @param BulkSaveOutcome $outcome What the save path lost, and why.
	 * @param array $extra Path-specific payload merged into the response body.
	 * @param bool $partial Whether the caller opted into best-effort semantics.
	 *
	 * @return JSONResponse The batch outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function bulkSaveResponse(
		string $successMessage,
		int $savedCount,
		int $requestedCount,
		BulkSaveOutcome $outcome,
		array $extra,
		bool $partial,
	): JSONResponse {
		$failedCount = $outcome->failedCount;
		$complete = $outcome->isComplete();

		$message = $successMessage;
		if ($complete === false) {
			$message = sprintf(
				'Bulk save incomplete: %d of %d objects were rejected and NOT written. See "failures" for the reason per object.',
				$failedCount,
				$requestedCount
			);
		}

		$status = Http::STATUS_UNPROCESSABLE_ENTITY;
		if ($complete === true || $partial === true) {
			$status = Http::STATUS_OK;
		}

		return new JSONResponse(
			data: array_merge(
				[
					'success' => $complete,
					'message' => $message,
					'saved_count' => $savedCount,
					'failed_count' => $failedCount,
					'requested_count' => $requestedCount,
					'failures' => $outcome->failures,
					'partial' => $partial,
				],
				$extra
			),
			statusCode: $status
		);

	}//end bulkSaveResponse()

	/**
	 * Perform bulk save operations on objects
	 *
	 * Request body:
	 *  - `objects`  (required) the rows to write.
	 *  - `stream`   (optional) row-at-a-time write path — see writeBatch().
	 *  - `partial`  (optional) opt into best-effort semantics: a batch with
	 *               rejected rows still answers 200 instead of 422. It does NOT
	 *               make `success` true — see bulkSaveResponse() for why.
	 *
	 * Response body always carries `saved_count`, `failed_count`,
	 * `requested_count` and `failures` (index / uuid / error / type per
	 * rejected object), so a shortfall names itself instead of hiding behind a
	 * count the caller has to think to compare.
	 *
	 * @param string $register The register identifier
	 * @param string $schema The schema identifier
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with bulk save operation results
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function save(string $register, string $schema): JSONResponse {
		try {
			// Resolve slugs to numeric IDs.
			try {
				$resolved = $this->resolveRegisterSchemaIds(
					register: $register,
					schema: $schema,
					objectService: $this->objectService
				);
			} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
			}

			// AUTHORIZATION (wave-11 WF1 / wave-3 C4 pattern): bulk-writing objects is a
			// potentially high-impact write that any authenticated user could otherwise use
			// to spray validation work or flood the audit trail.  Gate on manage-permission
			// for the target schema (default-SECURE: admin-only when no manage rule exists).
			// Mixed-schema (schema=0) bulk operations skip this gate because there is no
			// single schema entity to check against — the per-object RBAC flag (_rbac:true)
			// still runs inside saveObjects for individual object writes.
			$isMixedSchema = ($resolved['schema'] === 0);

			if ($isMixedSchema === false) {
				try {
					$schemaEntityForGate = $this->schemaMapper->find((int)$resolved['schema']);
				} catch (\Throwable $e) {
					return new JSONResponse(
						data: ['error' => 'Schema not found'],
						statusCode: Http::STATUS_NOT_FOUND
					);
				}

				if ($this->checkSchemaManagePermission(schema: $schemaEntityForGate) === false) {
					return new JSONResponse(
						data: ['error' => 'User does not have permission to bulk-write objects on this schema'],
						statusCode: Http::STATUS_FORBIDDEN
					);
				}
			}//end if

			// Get request data.
			$data = $this->request->getParams();
			$objects = $data['objects'] ?? [];

			// Validate input.
			if (empty($objects) === true || is_array($objects) === false) {
				return new JSONResponse(
					data: ['error' => 'Invalid input. "objects" array is required.'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Determine schema to use (null for mixed-schema, resolved for single-schema).
			$schemaToUse = $resolved['schema'];
			if ($isMixedSchema === true) {
				$schemaToUse = null;
			}

			// See writeBatch() for why `stream` is opt-in, and bulkSaveResponse()
			// for what `partial` does and does not change.
			return $this->writeBatch(
				objects: $objects,
				register: $resolved['register'],
				schema: $schemaToUse,
				stream: filter_var(($data['stream'] ?? false), FILTER_VALIDATE_BOOLEAN),
				partial: filter_var(($data['partial'] ?? false), FILTER_VALIDATE_BOOLEAN)
			);
		} catch (UniqueConstraintViolationException $e) {
			// WF3 (wave-11): a client-supplied UUID collided with an existing row at a
			// non-upsert boundary (e.g. unique-slug constraint, or concurrent insert race).
			// Surface a human-readable 422 instead of leaking a raw DBAL trace.
			return new JSONResponse(
				data: [
					'error' => 'One or more objects contain a UUID or unique field that conflicts with an existing record.',
					'error_code' => 'uuid_conflict',
					'detail' => $e->getMessage(),
				],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Bulk save operation failed: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end save()

	/**
	 * Delete all objects belonging to a specific schema
	 *
	 * Despite its name and its route (`/api/bulk/{register}/{schema}/delete-schema`),
	 * this endpoint does NOT delete the schema — it deletes the schema's OBJECTS. It
	 * is a near-duplicate of deleteSchemaObjects(), differing only in that it requires
	 * numeric ids instead of resolving slugs.
	 *
	 * @param string $register The register identifier
	 * @param string $schema The schema identifier
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with schema delete result
	 *
	 * @deprecated Use bulk#deleteSchemaObjects (`/api/bulk/{register}/{schema}/delete-objects`)
	 *             to delete a schema's objects, or `DELETE /api/schemas/{id}?deleteObjects=true`
	 *             to delete the schema AND its objects. Retained only for API back-compat;
	 *             the misleading name is documented rather than changed.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function deleteSchema(string $register, string $schema): JSONResponse {
		try {
			// Validate input.
			if (is_numeric($schema) === false) {
				return new JSONResponse(
					data: ['error' => 'Invalid schema ID. Must be numeric.'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Authorization: bulk-deleting every object in a schema is a
			// destructive data-model write. Gate on manage-permission for the
			// target schema (default-SECURE: admin-only when no manage rule
			// exists). Mirrors the wave-1 #1949 controller gate pattern.
			try {
				$schemaEntity = $this->schemaMapper->find((int)$schema);
			} catch (\Throwable $e) {
				return new JSONResponse(
					data: ['error' => 'Schema not found'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			if ($this->checkSchemaManagePermission(schema: $schemaEntity) === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to manage this schema'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			// Get request data. A JSON body delivers a real bool, but a form/query
			// request delivers the string "true" — which would be a TypeError against
			// the bool-typed service parameter, so normalize here.
			$data = $this->request->getParams();
			$hardDelete = filter_var(($data['hardDelete'] ?? false), FILTER_VALIDATE_BOOLEAN);

			// Set register and schema context.
			$this->objectService->setRegister($register);
			$this->objectService->setSchema($schema);

			// Perform schema deletion operation.
			$result = $this->objectService->deleteObjectsBySchema(
				registerId: (int)$register,
				schemaId: (int)$schema,
				hardDelete: $hardDelete
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Schema objects deletion completed successfully',
					'deleted_count' => $result['deleted_count'],
					'deleted_uuids' => $result['deleted_uuids'],
					'schema_id' => $result['schema_id'],
					'hard_delete' => $hardDelete,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Schema objects deletion failed: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end deleteSchema()

	/**
	 * Delete all objects belonging to a specific register and schema combination.
	 *
	 * This endpoint provides a convenient way to delete all objects for a given
	 * register/schema combination from the frontend action menu. It uses optimized
	 * SQL queries to delete objects efficiently from magic tables.
	 *
	 * @param string $register The register identifier (ID or slug).
	 * @param string $schema The schema identifier (ID or slug).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with deletion result.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-15
	 */
	public function deleteSchemaObjects(string $register, string $schema): JSONResponse {
		try {
			// Resolve register and schema slugs/IDs to numeric IDs.
			try {
				$resolved = $this->resolveRegisterSchemaIds(
					register: $register,
					schema: $schema,
					objectService: $this->objectService
				);
			} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
				return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
			}

			// Authorization: bulk-deleting every object for a register/schema
			// combination is a destructive data-model write. Gate on
			// manage-permission for the target schema (default-SECURE:
			// admin-only when no manage rule exists). Mirrors the wave-1
			// #1949 controller gate pattern.
			try {
				$schemaEntity = $this->schemaMapper->find($resolved['schema']);
			} catch (\Throwable $e) {
				return new JSONResponse(
					data: ['error' => 'Schema not found'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			if ($this->checkSchemaManagePermission(schema: $schemaEntity) === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to manage this schema'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			// Get request data. A JSON body delivers a real bool, but a form/query
			// request delivers the string "true" — which would be a TypeError against
			// the bool-typed service parameter, so normalize here.
			$data = $this->request->getParams();
			$hardDelete = filter_var(($data['hardDelete'] ?? false), FILTER_VALIDATE_BOOLEAN);

			// Set register and schema context using resolved IDs.
			$this->objectService->setRegister((string)$resolved['register']);
			$this->objectService->setSchema((string)$resolved['schema']);

			// Perform optimized deletion operation for this register/schema combination.
			$result = $this->objectService->deleteObjectsBySchema(
				registerId: $resolved['register'],
				schemaId: $resolved['schema'],
				hardDelete: $hardDelete
			);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Objects deletion completed successfully',
					'deleted_count' => $result['deleted_count'],
					'deleted_uuids' => $result['deleted_uuids'],
					'register_id' => $resolved['register'],
					'schema_id' => $result['schema_id'],
					'hard_delete' => $hardDelete,
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Objects deletion failed: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end deleteSchemaObjects()

	/**
	 * Delete all objects belonging to a specific register
	 *
	 * @param string $register The register identifier
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with register delete result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-15
	 */
	public function deleteRegister(string $register): JSONResponse {
		try {
			// Validate input.
			if (is_numeric($register) === false) {
				return new JSONResponse(
					data: ['error' => 'Invalid register ID. Must be numeric.'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Authorization: bulk-deleting every object in a register is a
			// destructive data-model write. Gate on manage-permission for the
			// target register (default-SECURE: admin-only when no manage rule
			// exists). Mirrors the wave-1 #1949 controller gate pattern.
			try {
				$registerEntity = $this->registerMapper->find((int)$register);
			} catch (\Throwable $e) {
				return new JSONResponse(
					data: ['error' => 'Register not found'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			if ($this->checkRegisterManagePermission(register: $registerEntity) === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to manage this register'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			// Set register context.
			$this->objectService->setRegister($register);

			// Perform register deletion operation.
			$result = $this->objectService->deleteObjectsByRegister((int)$register);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Register objects deletion completed successfully',
					'deleted_count' => $result['deleted_count'],
					'deleted_uuids' => $result['deleted_uuids'],
					'register_id' => $result['register_id'],
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Register objects deletion failed: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end deleteRegister()

	/**
	 * Run validation for all objects belonging to a specific schema.
	 *
	 * @param string $schema The schema identifier
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with validation result
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function runSchemaValidation(string $schema): JSONResponse {
		try {
			// Validate input.
			if (is_numeric($schema) === false) {
				return new JSONResponse(
					data: ['error' => 'Invalid schema ID. Must be numeric.'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Perform schema validation operation and return service result directly.
			$result = $this->objectService->validateObjectsBySchema((int)$schema);

			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			$errorMsg = 'Schema validation failed: ' . $e->getMessage();
			return new JSONResponse(
				data: ['error' => $errorMsg],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end runSchemaValidation()
}//end class
