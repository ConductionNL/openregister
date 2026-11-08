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
use OCA\OpenRegister\Controller\Support\BulkControllerServices;
use OCA\OpenRegister\Controller\Support\BulkSaveBatch;
use OCA\OpenRegister\Controller\Support\BulkSaveSummary;
use OCA\OpenRegister\Controller\Support\BulkStore;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Dto\BulkSaveOutcome;
use OCA\OpenRegister\Controller\Trait\ResolvesRegisterAndSchemaTrait;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Exception\LockedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\BatchOperationStatusInterface;
use OCA\OpenRegister\Service\Rbac\RbacCollaborators;
use OCA\OpenRegister\Service\Rbac\SchemaManageGateInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
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
	use ResolvesRegisterAndSchemaTrait;

	/**
	 * The most rows one atomic batch may carry. One transaction holds its locks
	 * until the last row is written, so an unbounded batch would hold them for
	 * as long as the client cares to send rows.
	 */
	public const ATOMIC_BATCH_LIMIT = 1000;

	/**
	 * The object service
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The bulk store; its object service also offers the streaming save
	 *
	 * @var BulkStore
	 */
	private readonly BulkStore $store;

	/**
	 * Mapper for resolving registers (RBAC gates)
	 *
	 * @var RegisterMapperInterface
	 */
	private readonly RegisterMapperInterface $registerMapper;

	/**
	 * Mapper for resolving schemas (RBAC gates)
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * User session for admin/manage checks
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for admin/manage checks
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * The connection an atomic batch runs its transaction on
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Who may manage a schema. Built from the collaborators above when null.
	 *
	 * @var ?SchemaManageGateInterface
	 */
	private readonly ?SchemaManageGateInterface $schemaManageGate;

	/**
	 * Constructor for the BulkController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param BulkControllerServices $services The bulk controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		BulkControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->store = $services->store;
		$this->objectService = $services->store->objectService;
		$this->registerMapper = $services->store->registerMapper;
		$this->schemaMapper = $services->store->schemaMapper;
		$this->userSession = $services->platform->userSession;
		$this->groupManager = $services->platform->groupManager;
		$this->db = $services->platform->db;
		$this->schemaManageGate = $services->store->schemaManageGate;
	}//end __construct()

	/**
	 * Check if the current user has 'manage' permission on a schema.
	 *
	 * Default-SECURE mirror of `SchemasController::checkSchemaManagePermission()`:
	 * a schema with no `manage` authorization rule can only be managed by
	 * administrators. When manage rules are present, membership of one of the
	 * listed groups grants permission (admins always pass). Deliberately NOT
	 * `PermissionHandlerInterface::hasPermission()`, which is default-OPEN for object
	 * data RBAC and therefore unsuitable for gating schema-definition writes.
	 *
	 * @param Schema $schema The schema to check manage permission for.
	 *
	 * @return bool True if the current user may manage this schema.
	 *
	 */
	private function checkSchemaManagePermission(Schema $schema): bool {
		return $this->manageGate()->mayManage(schema: $schema);
	}//end checkSchemaManagePermission()

	/**
	 * The refusal of a single-schema bulk write the caller may not manage, or null.
	 *
	 * Mixed-schema (0) batches name their schema per row and are checked against
	 * {@see SchemaManageGateInterface::firstUnmanageableRowSchema()} once the rows are read.
	 *
	 * @param int $schemaId The resolved schema id, 0 for mixed-schema.
	 *
	 * @return JSONResponse|null 404 or 403, or null when the write may go ahead.
	 */
	private function refusedSchemaGate(int $schemaId): ?JSONResponse {
		if ($schemaId === 0) {
			return null;
		}

		try {
			$schema = $this->schemaMapper->find($schemaId);
		} catch (\Throwable $e) {
			return new JSONResponse(data: ['error' => 'Schema not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($this->checkSchemaManagePermission(schema: $schema) === false) {
			return new JSONResponse(
				data: ['error' => 'User does not have permission to bulk-write objects on this schema'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refusedSchemaGate()

	/**
	 * The refusal of a mixed-schema batch naming a schema the caller may not manage, or null.
	 *
	 * @param bool         $isMixedSchema Whether the batch names its schema per row.
	 * @param array<mixed> $objects       The rows.
	 *
	 * @return JSONResponse|null 403, or null when every named schema passes or the batch is single-schema.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-mixed-schema-bulk-route-checks-every-schema-its-rows-name
	 */
	private function refusedRowSchemas(bool $isMixedSchema, array $objects): ?JSONResponse {
		if ($isMixedSchema === false) {
			return null;
		}

		$unmanageable = $this->manageGate()->firstUnmanageableRowSchema(objects: $objects);
		if ($unmanageable === null) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => 'User does not have permission to bulk-write objects on schema ' . $unmanageable],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end refusedRowSchemas()

	/**
	 * The schema manage gate, built from this controller's collaborators when none was injected.
	 *
	 * @return SchemaManageGateInterface The gate.
	 */
	private function manageGate(): SchemaManageGateInterface {
		return ($this->schemaManageGate ?? RbacCollaborators::schemaManageGate(
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			schemaMapper: $this->schemaMapper
		));
	}//end manageGate()

	/**
	 * The refusal of a delete that empties whole tables, or null for an administrator.
	 *
	 * These deletes filter on register and schema only, so they reach the rows of every
	 * organisation in the table. A `manage` rule answers for the schema's definition, not
	 * for those rows.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller is an administrator.
	 */
	private function wholeTableDeleteRefusal(): ?JSONResponse {
		if ($this->manageGate()->callerIsAdministrator() === true) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => 'Deleting every object of a schema or register is for administrators.'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end wholeTableDeleteRefusal()


	/**
	 * Resolve a register/schema path pair to numeric ids.
	 *
	 * This method used to hold its own copy of the resolution logic. That copy
	 * was identical to ObjectsController's until #2858 and #2860 fixed the
	 * other one — after which `GET /api/objects/19/9476` succeeded while
	 * `POST /api/bulk/19/9475/save` still answered
	 * `404 Register not found: '19'` for the same register. openregister#2820
	 * had survived its own fix, in the copy nobody edited.
	 *
	 * @param string        $register      Register slug, uuid or numeric id.
	 * @param string        $schema        Schema slug, uuid or numeric id.
	 * @param ObjectOperationsInterface $objectService The request's object service.
	 *
	 * @return array The resolved ids.
	 *
	 * @throws RegisterNotFoundException When the register does not resolve.
	 * @throws SchemaNotFoundException   When the schema does not resolve.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	private function resolveRegisterSchemaIds(string $register, string $schema, ObjectOperationsInterface $objectService): array {
		$resolved = $this->resolveRegisterAndSchema(
			register: $register,
			schema: $schema,
			objectService: $objectService
		);

		// Re-anchor on the resolved NUMERIC ids, as this controller did before:
		// downstream bulk handlers read the service's current register/schema
		// rather than the returned array.
		$objectService->setRegister(register: (string)$resolved['register'])
			->setSchema(schema: (string)$resolved['schema']);

		return ['register' => $resolved['register'], 'schema' => $resolved['schema']];
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
			$resolved = $this->resolveOrNotFound(register: $register, schema: $schema);
			if ($resolved instanceof JSONResponse) {
				return $resolved;
			}

			return $this->deleteResolved(resolved: $resolved);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Bulk delete operation failed: ' . $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end delete()

	/**
	 * Resolve a register/schema path pair, answering 404 when either is unknown.
	 *
	 * @param string $register Register slug, uuid or numeric id.
	 * @param string $schema   Schema slug, uuid or numeric id.
	 *
	 * @return array|JSONResponse The resolved ids, or the 404 answer.
	 */
	private function resolveOrNotFound(string $register, string $schema): array|JSONResponse {
		try {
			return $this->resolveRegisterSchemaIds(
				register: $register,
				schema: $schema,
				objectService: $this->objectService
			);
		} catch (RegisterNotFoundException|SchemaNotFoundException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		}
	}//end resolveOrNotFound()

	/**
	 * Validate the requested uuids and delete them in the resolved register and schema.
	 *
	 * @param array $resolved The resolved register and schema ids.
	 *
	 * @return JSONResponse 400 on invalid input, otherwise the delete outcome.
	 */
	private function deleteResolved(array $resolved): JSONResponse {
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

		// DEDUPLICATE BEFORE COUNTING. Naming the same object twice is one
		// deletion, and the service resolves each UUID once — so counting the
		// raw list as `requested_count` would leave `requested = deleted +
		// skipped` unsatisfiable for a caller that repeated an entry, which is
		// exactly the arithmetic this endpoint is supposed to make legible.
		$uuids = array_values(array_unique($uuids));

		// Set register and schema context using resolved IDs.
		$this->objectService->setRegister((string)$resolved['register']);
		$this->objectService->setSchema((string)$resolved['schema']);

		// Perform bulk delete operation with referential integrity enforcement per object.
		$result = $this->objectService->deleteObjects($uuids);

		return $this->bulkDeleteResponse(uuids: $uuids, result: $result);
	}//end deleteResolved()

	/**
	 * Build the bulk-delete answer, reporting any refused rows.
	 *
	 * @param array $uuids  The deduplicated uuids the caller asked to delete.
	 * @param array $result The service's delete outcome.
	 *
	 * @return JSONResponse The 200 answer.
	 */
	private function bulkDeleteResponse(array $uuids, array $result): JSONResponse {
		$deletedUuids = $result['deleted_uuids'];
		$skippedUuids = $result['skipped_uuids'];
		$skippedReasons = ($result['skipped_reasons'] ?? []);
		$cascadeCount = $result['cascade_count'];

		// `success` REPORTS THE SHORTFALL, as the bulk save path already does
		// (see bulkSaveResponse()). A row this endpoint refused is a row the caller
		// still believes it deleted, and a constant `true` said exactly that:
		// the live symptom of the filter bug was a 200 reading `success: true,
		// requested_count: 1, deleted_count: 0` over an untouched object.
		$message = 'Bulk delete operation completed successfully';
		if (empty($skippedUuids) === false) {
			$message = sprintf(
				'Bulk delete completed with %d of %d objects refused; see skipped_reasons.',
				count($skippedUuids),
				count($uuids)
			);
		}

		return new JSONResponse(
			data: [
				'success' => empty($skippedUuids),
				'message' => $message,
				'deleted_count' => count($deletedUuids),
				'deleted_uuids' => $deletedUuids,
				'requested_count' => count($uuids),
				'skipped_count' => count($skippedUuids),
				'skipped_uuids' => $skippedUuids,
				'skipped_reasons' => $skippedReasons,
				'cascade_count' => $cascadeCount,
				'total_affected' => count($deletedUuids) + $cascadeCount,
			]
		);
	}//end bulkDeleteResponse()

	/**
	 * Write a batch in one transaction: every row, or none (REQ-ATOMIC-001).
	 *
	 * The save path refuses invalid rows one by one and keeps writing the rest,
	 * so the transaction is what makes it all or nothing: when any row is
	 * refused, or the save throws, every row it wrote is rolled back and the
	 * answer names the first refused row. The per-chunk transactions inside the
	 * save nest as savepoints under this one. The bulk path dispatches no object
	 * events (`events: false`), so nothing leaves the transaction before it
	 * commits; the audit rows it writes are rolled back with the objects.
	 * `stream` and `partial` do not apply: the streaming path commits per row,
	 * and a partial result is exactly what an atomic batch refuses.
	 *
	 * @param BulkSaveBatch $batch The rows, register and schema; never partial.
	 *
	 * @return JSONResponse 200 when every row wrote; 422 naming the first refused row; 413 over the limit.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	private function writeAtomicBatch(BulkSaveBatch $batch): JSONResponse {
		$requestedCount = count($batch->objects);
		$refusal = $this->atomicBatchLimitRefusal(requestedCount: $requestedCount);
		if ($refusal !== null) {
			return $refusal;
		}

		$this->db->beginTransaction();
		try {
			$response = $this->writeCheckedBatch(batch: $batch);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $this->settleAtomicBatch(response: $response, requestedCount: $requestedCount);
	}//end writeAtomicBatch()

	/**
	 * Refuse an atomic batch over ATOMIC_BATCH_LIMIT rows.
	 *
	 * @param int $requestedCount The rows the caller sent.
	 *
	 * @return JSONResponse|null The 413 answer, or null when the batch fits.
	 */
	private function atomicBatchLimitRefusal(int $requestedCount): ?JSONResponse {
		if ($requestedCount <= self::ATOMIC_BATCH_LIMIT) {
			return null;
		}

		return new JSONResponse(
			data: [
				'error' => sprintf(
					'An atomic batch may carry at most %d rows; this one has %d. Split it, or send it without atomic.',
					self::ATOMIC_BATCH_LIMIT,
					$requestedCount
				),
				'limit' => self::ATOMIC_BATCH_LIMIT,
				'requested_count' => $requestedCount,
			],
			statusCode: Http::STATUS_REQUEST_ENTITY_TOO_LARGE
		);
	}//end atomicBatchLimitRefusal()

	/**
	 * Commit the atomic batch when every row wrote, otherwise roll it back.
	 *
	 * @param JSONResponse $response       The batch outcome written inside the transaction.
	 * @param int          $requestedCount The rows the caller sent.
	 *
	 * @return JSONResponse 200 when committed; 422 naming the first refused row.
	 */
	private function settleAtomicBatch(JSONResponse $response, int $requestedCount): JSONResponse {
		$data = $response->getData();
		if (is_array($data) === false) {
			$data = [];
		}

		if (($data['success'] ?? false) === true) {
			$this->db->commit();
			$data['atomic'] = true;
			return new JSONResponse(data: $data, statusCode: Http::STATUS_OK);
		}

		$this->db->rollBack();

		return $this->atomicRollbackResponse(data: $data, requestedCount: $requestedCount);
	}//end settleAtomicBatch()

	/**
	 * The 422 answer of a rolled-back atomic batch.
	 *
	 * @param array $data           The batch outcome that was rolled back.
	 * @param int   $requestedCount The rows the caller sent.
	 *
	 * @return JSONResponse The 422 answer.
	 */
	private function atomicRollbackResponse(array $data, int $requestedCount): JSONResponse {
		$failures = ($data['failures'] ?? []);
		$first = ($failures[0] ?? []);
		$reason = (string)($first['error'] ?? 'no reason recorded');
		$where = 'a row';
		if (isset($first['index']) === true) {
			$where = 'row '.(int)$first['index'];
		}

		return new JSONResponse(
			data: [
				'success' => false,
				'atomic' => true,
				'message' => sprintf('Atomic batch rolled back: %s was refused (%s). No row was written.', $where, $reason),
				'saved_count' => 0,
				'failed_count' => (int)($data['failed_count'] ?? count($failures)),
				'requested_count' => $requestedCount,
				'failures' => $failures,
				'partial' => false,
			],
			statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end atomicRollbackResponse()

	/**
	 * Write a batch row at a time, for administrators only.
	 *
	 * Streaming puts each row through saveObject(), which engages the
	 * reference-validation cache — repeated targets resolve from memory — and
	 * consumes the payload lazily. It also isolates failures per row rather than
	 * failing the whole call, which is why its `success` reflects the failed
	 * count instead of being a constant: a caller reading only the HTTP status
	 * would otherwise take a partial import for a complete one.
	 *
	 * @param BulkSaveBatch $batch The rows, register, schema and partial flag.
	 *
	 * @return JSONResponse The batch outcome, or 403 for a caller who is no administrator.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function writeStreamedBatch(BulkSaveBatch $batch): JSONResponse {
		$requestedCount = count($batch->objects);

		// Each streamed row goes straight to the save handler, past the per-row
		// create, update, draft and organisation checks of the default path.
		if ($this->manageGate()->callerIsAdministrator() === false) {
			return new JSONResponse(
				data: ['error' => 'Streaming bulk saves are for administrators. Send the batch without "stream".'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$status = $this->store->objectService->saveObjectsStreaming(
			objects: $batch->objects,
			register: $batch->register,
			schema: $batch->schema
		);

		$savedCount = ($status->getCreatedCount() + $status->getUpdatedCount());
		$accounted = ($savedCount + $status->getUnchangedCount());

		$summary = new BulkSaveSummary(
			successMessage: 'Bulk save operation completed (streaming)',
			savedCount: $savedCount,
			requestedCount: $requestedCount,
			extra: ['saved_objects' => $status->toArray()],
			accountedCount: $accounted
		);

		return $this->bulkSaveResponse(
			summary: $summary,
			outcome: $this->saveOutcome(result: $status, summary: $summary),
			partial: $batch->partial
		);
	}//end writeStreamedBatch()

	/**
	 * Write a batch through the checked default path.
	 *
	 * The default uses MagicMapper's ultraFastBulkSave: fastest writes, but it
	 * never consults the reference-validation cache, so rows that reference each
	 * other cost N×M database round-trips resolving them.
	 *
	 * @param BulkSaveBatch $batch The rows, register, schema and partial flag.
	 *
	 * @return JSONResponse The batch outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function writeCheckedBatch(BulkSaveBatch $batch): JSONResponse {
		$requestedCount = count($batch->objects);

		$savedObjects = $this->objectService->saveObjects(
			objects: $batch->objects,
			register: $batch->register,
			schema: $batch->schema,
			_rbac: true,
			_multitenancy: true,
			validation: true,
			events: false
		);

		$statistics = ($savedObjects['statistics'] ?? []);
		$savedCount = ((int)($statistics['saved'] ?? 0) + (int)($statistics['updated'] ?? 0));
		$accounted = ($savedCount + (int)($statistics['unchanged'] ?? 0));

		$summary = new BulkSaveSummary(
			successMessage: 'Bulk save operation completed successfully',
			savedCount: $savedCount,
			requestedCount: $requestedCount,
			extra: ['saved_objects' => $savedObjects],
			accountedCount: $accounted
		);

		return $this->bulkSaveResponse(
			summary: $summary,
			outcome: $this->saveOutcome(result: $savedObjects, summary: $summary),
			partial: $batch->partial
		);
	}//end writeCheckedBatch()

	/**
	 * What a save path lost, and why.
	 *
	 * @param BatchOperationStatusInterface|array $result  The streaming status, or the bulk result.
	 * @param BulkSaveSummary                     $summary The rows submitted and accounted for.
	 *
	 * @return BulkSaveOutcome
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BulkSaveOutcome::from*() are named
	 *   constructors on a value object, not calls into a collaborator — there is
	 *   nothing here a test would want to substitute.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function saveOutcome(BatchOperationStatusInterface|array $result, BulkSaveSummary $summary): BulkSaveOutcome {
		$requestedCount = $summary->requestedCount;
		$accountedCount = $summary->accountedCount;
		if ($result instanceof BatchOperationStatusInterface) {
			return BulkSaveOutcome::fromBatchStatus(
				status: $result,
				requestedCount: $requestedCount,
				accountedCount: $accountedCount
			);
		}

		return BulkSaveOutcome::fromBulkResult(
			bulkResult: $result,
			requestedCount: $requestedCount,
			accountedCount: $accountedCount
		);
	}//end saveOutcome()

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
	 * @param BulkSaveSummary $summary What the path wrote, and its success message and payload.
	 * @param BulkSaveOutcome $outcome What the save path lost, and why.
	 * @param bool            $partial Whether the caller opted into best-effort semantics.
	 *
	 * @return JSONResponse The batch outcome.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function bulkSaveResponse(BulkSaveSummary $summary, BulkSaveOutcome $outcome, bool $partial): JSONResponse {
		$failedCount = $outcome->failedCount;
		$complete = $outcome->isComplete();

		$message = $summary->successMessage;
		if ($complete === false) {
			$message = sprintf(
				'Bulk save incomplete: %d of %d objects were rejected and NOT written. See "failures" for the reason per object.',
				$failedCount,
				$summary->requestedCount
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
					'saved_count' => $summary->savedCount,
					'failed_count' => $failedCount,
					'requested_count' => $summary->requestedCount,
					'failures' => $outcome->failures,
					'partial' => $partial,
				],
				$summary->extra
			),
			statusCode: $status
		);

	}//end bulkSaveResponse()

	/**
	 * Perform bulk save operations on objects
	 *
	 * Request body:
	 *  - `objects`  (required) the rows to write.
	 *  - `stream`   (optional) row-at-a-time write path, for administrators only — see writeStreamedBatch().
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
			$resolved = $this->resolveOrNotFound(register: $register, schema: $schema);
			if ($resolved instanceof JSONResponse) {
				return $resolved;
			}

			return $this->saveResolved(resolved: $resolved);
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
	 * Gate and validate a bulk save into the resolved register and schema, then write it.
	 *
	 * @param array $resolved The resolved register and schema ids.
	 *
	 * @return JSONResponse The refusal, the 400 on invalid input, or the batch outcome.
	 */
	private function saveResolved(array $resolved): JSONResponse {
		// AUTHORIZATION (wave-11 WF1 / wave-3 C4 pattern): bulk-writing objects is a
		// potentially high-impact write that any authenticated user could otherwise use
		// to spray validation work or flood the audit trail.  Gate on manage-permission
		// for the target schema (default-SECURE: admin-only when no manage rule exists).
		// Mixed-schema (schema=0) bulk operations name their schema per row, so the
		// same gate runs for every schema the rows name (firstUnmanageableRowSchema).
		$isMixedSchema = ($resolved['schema'] === 0);
		$refused = $this->refusedSchemaGate(schemaId: (int)$resolved['schema']);
		if ($refused !== null) {
			return $refused;
		}

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

		$refused = $this->refusedRowSchemas(isMixedSchema: $isMixedSchema, objects: $objects);
		if ($refused !== null) {
			return $refused;
		}

		return $this->writeRequestedBatch(data: $data, resolved: $resolved);
	}//end saveResolved()

	/**
	 * Write an accepted batch through the path the request asked for.
	 *
	 * @param array $data     The request parameters: the validated `objects`, and atomic, stream, partial.
	 * @param array $resolved The resolved register and schema ids.
	 *
	 * @return JSONResponse The batch outcome.
	 */
	private function writeRequestedBatch(array $data, array $resolved): JSONResponse {
		$objects = $data['objects'];

		// Determine schema to use (null for mixed-schema, resolved for single-schema).
		$schemaToUse = $resolved['schema'];
		if ($resolved['schema'] === 0) {
			$schemaToUse = null;
		}

		// `atomic` wins over `stream` and `partial`: see writeAtomicBatch().
		if (filter_var(($data['atomic'] ?? false), FILTER_VALIDATE_BOOLEAN) === true) {
			return $this->writeAtomicBatch(
				batch: new BulkSaveBatch(objects: $objects, register: $resolved['register'], schema: $schemaToUse, partial: false)
			);
		}

		// `stream` is opt-in and defaults to the existing behaviour, because
		// neither path is universally better and the caller is the one who knows
		// the payload. Defaulting streaming on would silently slow down flat
		// payloads, and choosing automatically would need a threshold nobody has
		// measured. Both paths report a shortfall the same way — see
		// bulkSaveResponse() for what `partial` does and does not change.
		$batch = new BulkSaveBatch(
			objects: $objects,
			register: $resolved['register'],
			schema: $schemaToUse,
			partial: filter_var(($data['partial'] ?? false), FILTER_VALIDATE_BOOLEAN)
		);
		if (filter_var(($data['stream'] ?? false), FILTER_VALIDATE_BOOLEAN) === true) {
			return $this->writeStreamedBatch(batch: $batch);
		}

		return $this->writeCheckedBatch(batch: $batch);
	}//end writeRequestedBatch()

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
		$refusal = $this->wholeTableDeleteRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->deleteSchemaById(register: $register, schema: $schema);
		} catch (Exception $e) {
			return $this->tableDeleteFailure(exception: $e, prefix: 'Schema objects deletion failed: ');
		}
	}//end deleteSchema()

	/**
	 * Delete a schema's objects named by numeric register and schema ids.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The schema id.
	 *
	 * @return JSONResponse 400 on a non-numeric id, 404 on an unknown schema, otherwise the outcome.
	 */
	private function deleteSchemaById(string $register, string $schema): JSONResponse {
		// Validate input.
		if (is_numeric($schema) === false) {
			return new JSONResponse(
				data: ['error' => 'Invalid schema ID. Must be numeric.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$missing = $this->schemaNotFound(schemaId: (int)$schema);
		if ($missing !== null) {
			return $missing;
		}

		$hardDelete = $this->hardDeleteRequested();

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
	}//end deleteSchemaById()

	/**
	 * Answer 404 when the schema does not load.
	 *
	 * @param mixed $schemaId The schema id.
	 *
	 * @return JSONResponse|null The 404 answer, or null when the schema exists.
	 */
	private function schemaNotFound(mixed $schemaId): ?JSONResponse {
		try {
			$this->schemaMapper->find($schemaId);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: ['error' => 'Schema not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return null;
	}//end schemaNotFound()

	/**
	 * Whether the request asks for a hard delete.
	 *
	 * A JSON body delivers a real bool, but a form/query request delivers the
	 * string "true" — which would be a TypeError against the bool-typed service
	 * parameter, so normalize here.
	 *
	 * @return bool
	 */
	private function hardDeleteRequested(): bool {
		$data = $this->request->getParams();

		return filter_var(($data['hardDelete'] ?? false), FILTER_VALIDATE_BOOLEAN);
	}//end hardDeleteRequested()

	/**
	 * Map a failed table delete to its answer.
	 *
	 * A frozen row is a 409, a locked row a 423 and a schema holding legally
	 * retained records a 403 — deliberate refusals, not left to the generic 500.
	 *
	 * @param Exception $exception The failure.
	 * @param string    $prefix    The message prefix of the generic 500.
	 *
	 * @return JSONResponse The answer.
	 */
	private function tableDeleteFailure(Exception $exception, string $prefix): JSONResponse {
		if ($exception instanceof ObjectStateWriteException) {
			return new JSONResponse(data: $exception->toResponseBody(), statusCode: ObjectStateWriteException::HTTP_STATUS);
		}

		if ($exception instanceof LockedException) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: LockedException::HTTP_STATUS);
		}

		if ($exception instanceof ArchivalImmutableException) {
			return new JSONResponse(data: $exception->toResponseBody(), statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(
			data: ['error' => $prefix . $exception->getMessage()],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end tableDeleteFailure()

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
		$refusal = $this->wholeTableDeleteRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			// Resolve register and schema slugs/IDs to numeric IDs.
			$resolved = $this->resolveOrNotFound(register: $register, schema: $schema);
			if ($resolved instanceof JSONResponse) {
				return $resolved;
			}

			return $this->deleteResolvedSchemaObjects(resolved: $resolved);
		} catch (Exception $e) {
			return $this->tableDeleteFailure(exception: $e, prefix: 'Objects deletion failed: ');
		}
	}//end deleteSchemaObjects()

	/**
	 * Delete every object of the resolved register and schema.
	 *
	 * @param array $resolved The resolved register and schema ids.
	 *
	 * @return JSONResponse 404 on an unknown schema, otherwise the outcome.
	 */
	private function deleteResolvedSchemaObjects(array $resolved): JSONResponse {
		$missing = $this->schemaNotFound(schemaId: $resolved['schema']);
		if ($missing !== null) {
			return $missing;
		}

		$hardDelete = $this->hardDeleteRequested();

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
	}//end deleteResolvedSchemaObjects()

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
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-schema-wide-object-deletion-is-refused-on-an-archival-schema
	 */
	public function deleteRegister(string $register): JSONResponse {
		$refusal = $this->wholeTableDeleteRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->deleteRegisterById(register: $register);
		} catch (Exception $e) {
			return $this->tableDeleteFailure(exception: $e, prefix: 'Register objects deletion failed: ');
		}
	}//end deleteRegister()

	/**
	 * Delete every object of a register named by numeric id.
	 *
	 * @param string $register The register id.
	 *
	 * @return JSONResponse 400 on a non-numeric id, 404 on an unknown register, otherwise the outcome.
	 */
	private function deleteRegisterById(string $register): JSONResponse {
		// Validate input.
		if (is_numeric($register) === false) {
			return new JSONResponse(
				data: ['error' => 'Invalid register ID. Must be numeric.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$this->registerMapper->find((int)$register);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: ['error' => 'Register not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		// Same normalisation as deleteSchemaObjects(): a form or query request
		// delivers the string "true".
		$hardDelete = $this->hardDeleteRequested();

		// Set register context.
		$this->objectService->setRegister($register);

		// Perform register deletion operation.
		$result = $this->objectService->deleteObjectsByRegister(registerId: (int)$register, hardDelete: $hardDelete);

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Register objects deletion completed successfully',
				'deleted_count' => $result['deleted_count'],
				'deleted_uuids' => $result['deleted_uuids'],
				'register_id' => $result['register_id'],
				'hard_delete' => $hardDelete,
			]
		);
	}//end deleteRegisterById()

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
