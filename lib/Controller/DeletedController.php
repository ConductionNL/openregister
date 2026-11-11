<?php

/**
 * Class DeletedController
 *
 * Controller for managing soft deleted objects in the OpenRegister app.
 * Provides functionality for listing, filtering, restoring, and permanently deleting objects.
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
 * @spec openspec/specs/deletion-audit-trail/spec.md
 * @spec openspec/specs/deletion-audit-trail/spec.md
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Controller\Support\BatchOutcome;
use OCA\OpenRegister\Controller\Support\DeletedControllerServices;
use OCA\OpenRegister\Controller\Support\PurgeRequest;
use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;
use OCA\OpenRegister\Service\Deletion\DeletionWindowInterface;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionActionInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Service\Deletion\RecordDestructionCommand;
use OCA\OpenRegister\Service\Deletion\DeletionWindowQuery;

/**
 * Class DeletedController
 *
 * Controller for managing soft deleted objects
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One over, from the archival
 * gate: refusing a retained record needs the Schema it is declared on and the
 * ArchivalImmutableException that carries the wire contract. Both come from the
 * sanctioned delete path, which is the point — the alternative to that coupling
 * is a second, quietly different rule.
 */

class DeletedController extends Controller {
	/**
	 * Special parameters and system fields that are never a listing filter.
	 *
	 * @var list<string>
	 */
	private const LIST_CONTROL_PARAMS = [
		'limit',
		'_limit',
		'offset',
		'_offset',
		'page',
		'_page',
		'search',
		'_search',
		'sort',
		'_sort',
		'order',
		'_order',
		'_route',
		'id',
	];

	/**
	 * The verb a bulk outcome message uses, by the key its done count is reported under.
	 *
	 * @var array<string, string>
	 */
	private const BATCH_VERBS = [
		'restored' => 'Restored',
		'deleted'  => 'Permanently deleted',
	];

	/**
	 * The controller's injected services.
	 *
	 * @var DeletedControllerServices
	 */
	private readonly DeletedControllerServices $services;

	/**
	 * Constructor for the DeletedController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param DeletedControllerServices $services The deleted controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		DeletedControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->services = $services;
	}//end __construct()

	/**
	 * Helper method to extract request parameters for deleted objects
	 *
	 * @return array Request parameters including pagination and filters
	 */
	private function extractRequestParameters(): array {
		$params = $this->request->getParams();

		// Extract pagination parameters.
		$limit = (int)($params['limit'] ?? $params['_limit'] ?? 20);

		return [
			'limit' => $limit,
			'offset' => $this->requestedOffset(params: $params, limit: $limit),
			'page' => $this->aliasedInt(params: $params, name: 'page'),
			'filters' => $this->listFilters(params: $params),
			'sort' => $this->requestedSort(params: $params),
			'search' => $params['search'] ?? $params['_search'] ?? null,
		];
	}//end extractRequestParameters()

	/**
	 * A parameter given under its plain name or its underscore alias, as an integer.
	 *
	 * @param array  $params The request parameters.
	 * @param string $name   The plain name (`offset`, `page`).
	 *
	 * @return integer|null The value, or null when neither name carries one.
	 */
	private function aliasedInt(array $params, string $name): ?int {
		foreach ([$name, '_' . $name] as $key) {
			if (($params[$key] ?? null) !== null) {
				return (int)$params[$key];
			}
		}

		return null;
	}//end aliasedInt()

	/**
	 * The requested offset; a page without an offset is converted with the limit.
	 *
	 * @param array   $params The request parameters.
	 * @param integer $limit  The page size.
	 *
	 * @return integer|null The offset, or null when neither an offset nor a page is given.
	 */
	private function requestedOffset(array $params, int $limit): ?int {
		$offset = $this->aliasedInt(params: $params, name: 'offset');
		if ($offset !== null) {
			return $offset;
		}

		$page = $this->aliasedInt(params: $params, name: 'page');
		if ($page === null) {
			return null;
		}

		return ($page - 1) * $limit;
	}//end requestedOffset()

	/**
	 * The requested sort, by updated (last modified) DESC when none is given.
	 *
	 * @param array $params The request parameters.
	 *
	 * @return array<string, string> One field mapped to its order.
	 */
	private function requestedSort(array $params): array {
		if (($params['sort'] ?? null) === null && ($params['_sort'] ?? null) === null) {
			// Default sort by updated (last modified) which includes soft delete time.
			// Note: Cannot sort by 'deleted' directly as it's a JSON column in PostgreSQL.
			return ['updated' => 'DESC'];
		}

		$sortField = $params['sort'] ?? $params['_sort'] ?? 'updated';
		$sortOrder = $params['order'] ?? $params['_order'] ?? 'DESC';
		return [$sortField => $sortOrder];
	}//end requestedSort()

	/**
	 * The request parameters minus the special parameters and system fields.
	 *
	 * @param array $params The request parameters.
	 *
	 * @return array The filters.
	 */
	private function listFilters(array $params): array {
		return array_filter(
			$params,
			function ($key) {
				return !in_array($key, self::LIST_CONTROL_PARAMS);
			},
			ARRAY_FILTER_USE_KEY
		);
	}//end listFilters()

	/**
	 * Attach the published recovery window to every row of a trash listing.
	 *
	 * A window that lives only as a `purgeDate` inside the deletion blob is
	 * one nobody reads. Each row is returned as its serialised form plus a
	 * `deletionWindow` naming the destroyable-from date, the days remaining
	 * and the rule that set the retention.
	 *
	 * @param array<int, ObjectEntity> $objects The soft-deleted rows.
	 *
	 * @return array<int, array<string, mixed>> The rows with their window.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function withWindows(array $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			$row = $object->jsonSerialize();
			$window = $this->services->store->deletion->window->windowOf(
				query: new DeletionWindowQuery(object: $object, schema: $this->services->access->authorizer->resolveSchema(object: $object))
			);

			$row['deletionWindow'] = null;
			if ($window !== null) {
				$row['deletionWindow'] = $window->toArray();
			}

			$rows[] = $row;
		}

		return $rows;
	}//end withWindows()

	/**
	 * Get all soft deleted objects
	 *
	 * @return JSONResponse JSON response containing deleted objects
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|500,
	 *     array{error?: string,
	 *     results?: list<array<string, mixed>>, total?: int,
	 *     page?: int, pages?: 1|float, limit?: int|null, offset?: int|null},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function index(): JSONResponse {
		$params = $this->extractRequestParameters();

		// Read-scoped for a non-admin, as the object list is (openregister#4078).
		$scoped = ($this->services->access->authorizer->isCurrentUserAdmin() === false);

		try {
			// Objects live in per-register/schema magic tables, so there is no
			// single table for searchObjectsPaginated() to query without a
			// register/schema context — it always fell through to an empty
			// result. Scan every magic table for soft-deleted rows directly.
			$deletedObjects = $this->services->store->objectEntityMapper->findDeletedAcrossAllMagicTables(
				limit: $params['limit'],
				offset: $params['offset'],
				_rbac: $scoped,
				_multitenancy: $scoped
			);
			$total = $this->services->store->objectEntityMapper->countDeletedAcrossAllMagicTables(_rbac: $scoped, _multitenancy: $scoped);

			// Same render boundary as a live row: no write-only or unreadable property leaves.
			$deletedObjects = array_values($deletedObjects);
			$this->services->access->renderObject->redactWriteOnlyFromRows(rows: $deletedObjects, _rbac: $scoped);

			// Calculate pagination.
			$pages = 1;
			if (($params['limit'] ?? null) !== null && ($params['limit'] > 0) === true) {
				$pages = ceil($total / $params['limit']);
			}

			return new JSONResponse(
				data: [
					'results' => $this->withWindows(objects: $deletedObjects),
					'total' => $total,
					'page' => $params['page'] ?? 1,
					'pages' => $pages,
					'limit' => $params['limit'],
					'offset' => $params['offset'],
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'Failed to retrieve deleted objects: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end index()

	/**
	 * Get statistics for deleted objects
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with deletion statistics
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-16
	 */
	public function statistics(): JSONResponse {
		try {
			// Count soft-deleted rows across every magic table. countAll() with
			// no register/schema context returns 0 (it cannot pick a table), so
			// the dedicated cross-table count is required, read-scoped (#4078).
			$scoped = ($this->services->access->authorizer->isCurrentUserAdmin() === false);
			$totalDeleted = $this->services->store->objectEntityMapper->countDeletedAcrossAllMagicTables(_rbac: $scoped, _multitenancy: $scoped);

			// Get deleted today count.
			$today = (new DateTime())->format('Y-m-d');
			$deletedToday = $this->services->store->objectEntityMapper->countAll(
				_filters: [
					'@self.deleted' => 'IS NOT NULL',
					'@self.deleted.deleted' => '>=' . $today,
				],
			);

			// Get deleted this week count.
			$weekAgo = (new DateTime())->modify('-7 days')->format('Y-m-d');
			$deletedThisWeek = $this->services->store->objectEntityMapper->countAll(
				_filters: [
					'@self.deleted' => 'IS NOT NULL',
					'@self.deleted.deleted' => '>=' . $weekAgo,
				],
			);

			// Calculate oldest deletion (placeholder for now).
			$oldestDays = 0;
			// TODO: Calculate actual oldest deletion.
			return new JSONResponse(
				data: [
					'totalDeleted' => $totalDeleted,
					'deletedToday' => $deletedToday,
					'deletedThisWeek' => $deletedThisWeek,
					'oldestDays' => $oldestDays,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'Failed to get statistics: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end statistics()

	/**
	 * Get top deleters statistics
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with top deleters data
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-16
	 */
	public function topDeleters(): JSONResponse {
		// SEC-CTRL: admin-only — cross-user deletion analytics (usernames are
		// PII); a tenant-wide management surface, not per-user data.
		if ($this->services->access->authorizer->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
		}

		try {
			// TODO: Implement aggregation query to get top deleters from deleted objects.
			// For now, return mock data structure.
			$topDeleters = [
				['user' => 'admin', 'count' => 0],
				['user' => 'user1', 'count' => 0],
				['user' => 'user2', 'count' => 0],
			];

			return new JSONResponse(data: $topDeleters);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'Failed to get top deleters: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}
	}//end topDeleters()

	/**
	 * Restore a deleted object
	 *
	 * @param string $id The ID or UUID of the object to restore
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with restore result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function restore(string $id): JSONResponse {
		if ($this->services->access->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			return $this->restoreFound(object: $this->services->store->objectEntityMapper->find($id, null, null, true));
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'Failed to restore object: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end restore()

	/**
	 * Restore one soft-deleted object, the caller's update right in front, and record it.
	 *
	 * @param ObjectEntity $object The full stored row.
	 *
	 * @return JSONResponse The outcome: 200, or 400 when not deleted, 403 without the right.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function restoreFound(ObjectEntity $object): JSONResponse {
		if ($object->isSoftDeleted() === false) {
			return new JSONResponse(
				data: [
					'error' => 'Object is not deleted',
				],
				statusCode: 400
			);
		}

		// Restoring is a write.
		if ($this->services->access->authorizer->userMayActOnDeletedObject(object: $object, action: 'update') === false) {
			return new JSONResponse(
				data: ['error' => 'User does not have permission to restore this object'],
				statusCode: 403
			);
		}

		$window = $this->services->store->deletion->window->windowOf(
			query: new DeletionWindowQuery(object: $object, schema: $this->services->access->authorizer->resolveSchema(object: $object))
		);

		// Objects live in per-register/schema magic tables, so the restore goes through MagicMapper.
		$this->services->store->objectEntityMapper->restoreObject(uuid: (string)$object->getUuid());

		// A restore is one act, and it is recorded with its actor. Without
		// this the trail shows an object deleted and then, without
		// explanation, present again.
		$this->recordRestore(object: $object, window: $window);

		$data = [
			'success' => true,
			'message' => 'Object restored successfully',
		];
		if ($window !== null) {
			$data['restoredWithin'] = $window->toArray();
		}

		return new JSONResponse(data: $data);
	}//end restoreFound()

	/**
	 * Record a restore with the actor who made it.
	 *
	 * Failure is logged into the response path rather than thrown: a restore
	 * that succeeded must not be reported as failed because its record could
	 * not be written. That is the opposite trade-off to a DESTRUCTION, which
	 * refuses when unrecordable, and the difference is that a restore is
	 * reversible and a destruction is not.
	 *
	 * @param ObjectEntity $object The object restored.
	 * @param DeletionWindowInterface|null $window The window it was restored inside.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function recordRestore(ObjectEntity $object, mixed $window): void {
		$user = $this->services->access->userSession->getUser();
		$actor = 'system';
		$actorName = 'System';
		if ($user !== null) {
			$actor = $user->getUID();
			$actorName = $user->getDisplayName();
		}

		$context = [
			'restoredBy' => $actor,
			'restoredAt' => (new DateTime())->format(DateTime::ATOM),
			'objectUuid' => (string)$object->getUuid(),
		];
		if ($window !== null) {
			$context['restoredWithin'] = $window->toArray();
		}

		try {
			$this->services->store->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $object,
					action: 'object.restored',
					context: $context,
					options: new ObjectAuditEventOptions(actor: new NamedActor(id: $actor, name: $actorName))
				)
			);
		} catch (\Throwable $e) {
			// Swallowed on purpose, see the docblock.
		}
	}//end recordRestore()

	/**
	 * Restore multiple deleted objects
	 *
	 * Each soft-deleted object is gated through PermissionHandlerInterface with the
	 * `update` action against the object's resolved schema. Admins bypass.
	 * Objects whose schema cannot be resolved or for which the caller lacks
	 * `update` permission are skipped (counted as `failed`) so a partial
	 * cross-tenant bulk restore cannot succeed silently. This closes the
	 * wave-3 C4 finding (no RBAC on `restoreMultiple`).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with multiple restore result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function restoreMultiple(): JSONResponse {
		if ($this->services->access->userSession->getUser() === null) {
			return $this->notAuthenticated();
		}

		$ids = $this->request->getParam('ids', []);
		if (empty($ids) === true) {
			return $this->noObjectIdsProvided();
		}

		try {
			return $this->restoreFoundBatch(ids: $ids);
		} catch (\Exception $e) {
			return $this->batchFailure(message: 'Failed to restore objects: ' . $e->getMessage());
		}
	}//end restoreMultiple()

	/**
	 * Restore every soft-deleted object among the requested uuids and report the counts.
	 *
	 * Resolve across ALL magic tables, including deleted rows. findAll()
	 * without register/schema context returns [] (it cannot know which
	 * magic table to query), so a UUID-keyed cross-table lookup is
	 * required to actually find the soft-deleted objects.
	 *
	 * @param array $ids The requested uuids.
	 *
	 * @return JSONResponse The restored, failed and not-found counts.
	 */
	private function restoreFoundBatch(array $ids): JSONResponse {
		$objects = $this->services->store->objectEntityMapper->findMultipleAcrossAllMagicTables(
			uuids: $ids,
			includeDeleted: true
		);

		$restored = $this->restoreEach(objects: $objects);
		$notFound = $this->notFoundCount(ids: $ids, objects: $objects);
		$outcome  = new BatchOutcome(done: $restored, failed: count($objects) - $restored + $notFound, notFound: $notFound);

		return $this->batchResponse(outcome: $outcome, doneKey: 'restored');
	}//end restoreFoundBatch()

	/**
	 * Restore each found row of a bulk restore.
	 *
	 * @param ObjectEntity[] $objects The slim rows the lookup found.
	 *
	 * @return integer How many were restored; every other row failed.
	 */
	private function restoreEach(array $objects): int {
		$restored = 0;
		foreach ($objects as $object) {
			if ($this->restoredInBatch(object: $object) === true) {
				$restored++;
			}
		}

		return $restored;
	}//end restoreEach()

	/**
	 * Restore one row of a bulk restore, the way the single route does.
	 *
	 * The lookup's rows are slim: each is read in full and restored as the single route does.
	 *
	 * @param ObjectEntity $object The slim row the lookup found.
	 *
	 * @return boolean Whether it was restored; a failure of any kind counts as not.
	 */
	private function restoredInBatch(ObjectEntity $object): bool {
		try {
			return $this->restoreFound(object: $this->fullDeletedRow(uuid: (string)$object->getUuid()))->getStatus() === 200;
		} catch (\Exception $e) {
			return false;
		}
	}//end restoredInBatch()

	/**
	 * Permanently delete an object
	 *
	 * Gated through PermissionHandlerInterface with the `delete` action against the
	 * resolved schema (admins bypass). When register/schema context cannot
	 * be derived the call is refused fail-closed. This closes the wave-3 C4
	 * finding (no RBAC on `destroy`).
	 *
	 * @param string $id The ID or UUID of the object to permanently delete
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with deletion result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroy(string $id): JSONResponse {
		if ($this->services->access->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			return $this->destroyFound(object: $this->fullDeletedRow(uuid: $id));
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'Failed to permanently delete object: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end destroy()

	/**
	 * Destroy one soft-deleted object, every refusal in front, and record it; `force` waives the open window.
	 *
	 * @param ObjectEntity $object The full stored row.
	 *
	 * @return JSONResponse The destruction record, or the refusal naming its rule.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function destroyFound(ObjectEntity $object): JSONResponse {
		// An archival record is refused BEFORE the trash check, so a caller
		// holding a live archival record is told the record is immutable
		// rather than "not deleted yet". Then a purge only empties the
		// trash, never a live object.
		$refusal = $this->refuseUndestroyable(object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		$schema = $this->services->access->authorizer->resolveSchema(object: $object);
		$purge = new PurgeRequest(
			object: $object,
			schema: $schema,
			window: $this->services->store->deletion->window->windowOf(query: new DeletionWindowQuery(object: $object, schema: $schema)),
			force: filter_var($this->request->getParam('force', false), FILTER_VALIDATE_BOOLEAN)
		);

		// The right, both retention clocks and the open recovery window each
		// refuse in turn, naming the rule that refused.
		$refusal = $this->refuseDestruction(purge: $purge);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->performDestruction(purge: $purge);
	}//end destroyFound()

	/**
	 * The stored row of an object, deleted or not, with every column.
	 *
	 * @param string $uuid The id or uuid.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function fullDeletedRow(string $uuid): ObjectEntity {
		return $this->services->store->objectEntityMapper->find(identifier: $uuid, register: null, schema: null, includeDeleted: true);
	}//end fullDeletedRow()

	/**
	 * Refuse a purge of an object that is not a destroyable trash row.
	 *
	 * Two refusals, in order: an archival record is immutable, and a purge only
	 * empties the trash so a row that is not soft-deleted is never destroyed.
	 * `getDeleted() === null` never answered the trash question — the property
	 * defaults to `[]` and a live row keeps that default — so the check goes
	 * through ObjectEntity::isSoftDeleted().
	 *
	 * @param ObjectEntity $object The object being purged.
	 *
	 * @return JSONResponse|null The refusal, or null when the purge may proceed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function refuseUndestroyable(ObjectEntity $object): ?JSONResponse {
		$refusal = $this->services->access->authorizer->archivalRefusal(object: $object);
		if ($refusal !== null) {
			return new JSONResponse(
				data: $refusal->toResponseBody(),
				statusCode: 403
			);
		}

		if ($object->isSoftDeleted() === false) {
			return new JSONResponse(
				data: [
					'error' => 'Object is not deleted',
				],
				statusCode: 400
			);
		}

		return null;
	}//end refuseUndestroyable()

	/**
	 * Refuse a destruction that the right, the clocks or the window forbid.
	 *
	 * Three refusals, in order: DESTROYING IS A RIGHT, NOT AN ADMIN CHECK, so a
	 * caller lacking it is refused with the rule named; A HOLD OUTRANKS BOTH
	 * CLOCKS, and two clocks that disagree refuse rather than pick a winner in
	 * silence; and THE WINDOW IS A REFUSAL, NOT DECORATION — inside it the
	 * object can still come back, so destroying it needs the window waived
	 * explicitly and the refusal says how long is left.
	 *
	 * @param PurgeRequest $purge The object, its schema, its recovery window and the caller's waiver.
	 *
	 * @return JSONResponse|null The refusal, or null when the destruction may proceed.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function refuseDestruction(PurgeRequest $purge): ?JSONResponse {
		$object = $purge->object;
		$refusal = $this->services->store->deletion->destroyRight->refusalFor(object: $object, schema: $purge->schema);
		if ($refusal !== null) {
			return new JSONResponse(
				data: $refusal->toResponseBody(),
				statusCode: $refusal->getStatusCode()
			);
		}

		$clockRefusal = $this->services->store->deletion->clock->refusalFor(object: $object);
		if ($clockRefusal !== null) {
			return new JSONResponse(
				data: $clockRefusal->toResponseBody(),
				statusCode: $clockRefusal->getStatusCode()
			);
		}

		if ($purge->windowRefuses() === true) {
			return new JSONResponse(
				data: [
					'error' => 'DESTRUCTION_REFUSED',
					'rule' => 'recovery-window-open',
					'message' => 'This object can still be restored until '
						. $purge->window->destroyableFrom()->format('Y-m-d') . '. Destroying it before then '
						. 'requires the window to be waived explicitly.',
					'deletionWindow' => $purge->window->toArray(),
				],
				statusCode: 409
			);
		}

		return null;
	}//end refuseDestruction()

	/**
	 * Preview the scope, record the destruction, then carry it out.
	 *
	 * The record is written before the object is deleted, so a crash between
	 * them leaves an over-recorded destruction rather than an unrecorded one. A
	 * scope this instance cannot honour refuses before anything is touched.
	 *
	 * @param PurgeRequest $purge The object, its schema, its recovery window and the caller's waiver.
	 *
	 * @return JSONResponse The destruction record, or the scope refusal.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function performDestruction(PurgeRequest $purge): JSONResponse {
		$object = $purge->object;
		try {
			$scopeReport = $this->services->store->deletion->destruction->scope->destroy(object: $object, schema: $purge->schema);
			$record = $this->services->store->deletion->destruction->recorder->recordDestruction(
				command: new RecordDestructionCommand(
					object: $object,
					scope: $scopeReport,
					rule: 'destroy-right-granted',
					context: [
						'deletionWindow' => $purge->window?->toArray(),
						'windowWaived' => ($purge->force === true),
					]
				)
			);
		} catch (DestructionRefusedException $e) {
			return new JSONResponse(
				data: $e->toResponseBody(),
				statusCode: $e->getStatusCode()
			);
		}

		// Permanently delete the object. The record above is already
		// written, so a crash here leaves an over-recorded destruction
		// rather than an unrecorded one.
		$this->services->store->objectEntityMapper->delete($object);

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Object permanently deleted',
				'destruction' => $record,
			]
		);
	}//end performDestruction()

	/**
	 * Preview what a destruction would take with the object.
	 *
	 * Answers before the act, with a count per declared scope member, so
	 * nobody has to infer destruction from a foreign key. A scope naming a
	 * member this instance cannot honour comes back `destroyable: false` with
	 * the member named, which is the same answer the act itself gives.
	 *
	 * @param string $id The ID or UUID of the soft-deleted object.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The preview, the window and both clocks.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destructionPreview(string $id): JSONResponse {
		if ($this->services->access->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			$object = $this->services->store->objectEntityMapper->find(identifier: $id, register: null, schema: null, includeDeleted: true);
			$schema = $this->services->access->authorizer->resolveSchema(object: $object);

			$refusal = $this->services->store->deletion->destroyRight->refusalFor(object: $object, schema: $schema);
			if ($refusal !== null) {
				return new JSONResponse(
					data: $refusal->toResponseBody(),
					statusCode: $refusal->getStatusCode()
				);
			}

			$window = $this->services->store->deletion->window->windowOf(query: new DeletionWindowQuery(object: $object, schema: $schema));

			return new JSONResponse(
				data: [
					'objectUuid' => (string)$object->getUuid(),
					'preview' => $this->services->store->deletion->destruction->scope->preview(object: $object, schema: $schema),
					'deletionWindow' => $window?->toArray(),
					'clocks' => $this->services->store->deletion->clock->clocksFor(object: $object),
				]
			);
		} catch (DoesNotExistException $e) {
			// The lookup is read-scoped: an unreadable object is absent, not a server error.
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Failed to preview the destruction: ' . $e->getMessage()],
				statusCode: 500
			);
		}//end try
	}//end destructionPreview()

	/**
	 * Read the destruction records of an object, including one whose object is
	 * already gone.
	 *
	 * Keyed on the UUID rather than the register/schema path the audit-trail
	 * endpoint uses, because that path resolves through the object row and a
	 * destroyed object has none. A destruction record with no object is the
	 * point, so it needs a door that does not go through the object.
	 *
	 * @param string $id The UUID of the destroyed object.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The destruction records, newest first.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destructionRecord(string $id): JSONResponse {
		if ($this->services->access->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			$records = $this->services->access->authorizer->readableDestructionRecords(
				records: $this->services->store->auditTrailMapper->findForObjectByAction(
					objectUuid: $id,
					actions: [DestructionActionInterface::DESTRUCTION_ACTION]
				)
			);

			return new JSONResponse(
				data: [
					'objectUuid' => $id,
					'total' => count($records),
					'results' => array_map(
						static fn (\OCA\OpenRegister\Db\AuditTrail $record): array => $record->jsonSerialize(),
						$records
					),
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: ['error' => 'Failed to read the destruction record: ' . $e->getMessage()],
				statusCode: 500
			);
		}//end try
	}//end destructionRecord()

	/**
	 * Permanently delete multiple objects
	 *
	 * Each soft-deleted object is gated through PermissionHandlerInterface with the
	 * `delete` action against the object's resolved schema. Admins bypass.
	 * Objects whose schema cannot be resolved or for which the caller lacks
	 * `delete` permission are skipped (counted as `failed`) so a partial
	 * cross-tenant bulk wipe cannot succeed silently. This closes the
	 * wave-3 C4 finding (no RBAC on `destroyMultiple`).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse JSON response with multiple deletion result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroyMultiple(): JSONResponse {
		if ($this->services->access->userSession->getUser() === null) {
			return $this->notAuthenticated();
		}

		$ids = $this->request->getParam('ids', []);
		if (empty($ids) === true) {
			return $this->noObjectIdsProvided();
		}

		try {
			return $this->destroyFoundBatch(ids: $ids);
		} catch (\Exception $e) {
			return $this->batchFailure(message: 'Failed to permanently delete objects: ' . $e->getMessage());
		}
	}//end destroyMultiple()

	/**
	 * Permanently delete every destroyable object among the requested uuids and report the counts.
	 *
	 * Resolve across ALL magic tables, including deleted rows. findAll()
	 * without register/schema context returns [], so a UUID-keyed
	 * cross-table lookup is required to find the soft-deleted objects.
	 *
	 * @param array $ids The requested uuids.
	 *
	 * @return JSONResponse The deleted, failed and not-found counts.
	 */
	private function destroyFoundBatch(array $ids): JSONResponse {
		$objects = $this->services->store->objectEntityMapper->findMultipleAcrossAllMagicTables(
			uuids: $ids,
			includeDeleted: true
		);

		$deleted  = $this->destroyEach(objects: $objects);
		$notFound = $this->notFoundCount(ids: $ids, objects: $objects);
		$outcome  = new BatchOutcome(done: $deleted, failed: count($objects) - $deleted + $notFound, notFound: $notFound);

		return $this->batchResponse(outcome: $outcome, doneKey: 'deleted');
	}//end destroyFoundBatch()

	/**
	 * Permanently delete each found row of a bulk destroy.
	 *
	 * @param ObjectEntity[] $objects The slim rows the lookup found.
	 *
	 * @return integer How many were deleted; every other row failed.
	 */
	private function destroyEach(array $objects): int {
		$deleted = 0;
		foreach ($objects as $object) {
			if ($this->destroyedInBatch(object: $object) === true) {
				$deleted++;
			}
		}

		return $deleted;
	}//end destroyEach()

	/**
	 * Destroy one row of a bulk destroy: the delete right, then every refusal of the single route.
	 *
	 * The lookup's rows are slim: each is read in full first.
	 *
	 * @param ObjectEntity $object The slim row the lookup found.
	 *
	 * @return boolean Whether it was deleted; a refusal or failure of any kind counts as not.
	 */
	private function destroyedInBatch(ObjectEntity $object): bool {
		try {
			$full = $this->fullDeletedRow(uuid: (string)$object->getUuid());
			if ($this->services->access->authorizer->userMayActOnDeletedObject(object: $full, action: 'delete') === false) {
				return false;
			}

			return $this->destroyFound(object: $full)->getStatus() === 200;
		} catch (\Exception $e) {
			return false;
		}
	}//end destroyedInBatch()

	/**
	 * How many requested uuids the lookup did not find.
	 *
	 * @param array          $ids     The requested uuids.
	 * @param ObjectEntity[] $objects The rows the lookup found.
	 *
	 * @return integer The count of requested uuids with no row.
	 */
	private function notFoundCount(array $ids, array $objects): int {
		$foundIds = [];
		foreach ($objects as $object) {
			$foundIds[] = $object->getUuid();
		}

		return count(array_diff($ids, $foundIds));
	}//end notFoundCount()

	/**
	 * The response of a finished bulk restore or destroy.
	 *
	 * @param BatchOutcome $outcome The counts.
	 * @param string       $doneKey The key the done count is reported under (`restored`, `deleted`).
	 *
	 * @return JSONResponse The counts and their message.
	 */
	private function batchResponse(BatchOutcome $outcome, string $doneKey): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				$doneKey => $outcome->done,
				'failed' => $outcome->failed,
				'notFound' => $outcome->notFound,
				'message' => $outcome->message(verb: self::BATCH_VERBS[$doneKey]),
			]
		);
	}//end batchResponse()

	/**
	 * The 500 of a bulk restore or destroy that failed as a whole.
	 *
	 * @param string $message The error message.
	 *
	 * @return JSONResponse The error response.
	 */
	private function batchFailure(string $message): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => $message,
			],
			statusCode: 500
		);
	}//end batchFailure()

	/**
	 * The 401 of a bulk request without a signed-in user.
	 *
	 * @return JSONResponse The error response.
	 */
	private function notAuthenticated(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Not authenticated'],
			statusCode: 401
		);
	}//end notAuthenticated()

	/**
	 * The 400 of a bulk request without object ids.
	 *
	 * @return JSONResponse The error response.
	 */
	private function noObjectIdsProvided(): JSONResponse {
		return new JSONResponse(
			data: [
				'error' => 'No object IDs provided',
			],
			statusCode: 400
		);
	}//end noObjectIdsProvided()
}//end class
