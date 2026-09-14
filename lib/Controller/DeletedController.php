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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Service\Deletion\DeletionWindowService;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScope;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

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
	 * Constructor for the DeletedController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param MagicMapper $objectEntityMapper The object entity mapper
	 * @param RegisterMapper $registerMapper The register mapper
	 * @param SchemaMapper $schemaMapper The schema mapper
	 * @param IUserSession $userSession The user session
	 * @param IGroupManager $groupManager The group manager for admin checks
	 * @param PermissionHandler $permissionHandler Handler for per-schema RBAC checks
	 * @param DeletionWindowService $windowService Publishes the recovery window
	 * @param DestroyRightService $destroyRightService Decides whether the caller may destroy
	 * @param DestructionScopeService $scopeService Previews and carries out the declared scope
	 * @param DestructionRecorder $destructionRecorder Writes the record that survives the object
	 * @param RetentionClockService $clockService Reads the AVG and Archiefwet clocks
	 * @param AuditTrailMapper $auditTrailMapper Reads back a destruction record and records a restore
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MagicMapper $objectEntityMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly PermissionHandler $permissionHandler,
		private readonly DeletionWindowService $windowService,
		private readonly DestroyRightService $destroyRightService,
		private readonly DestructionScopeService $scopeService,
		private readonly DestructionRecorder $destructionRecorder,
		private readonly RetentionClockService $clockService,
		private readonly AuditTrailMapper $auditTrailMapper,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Check if the current user is an admin
	 *
	 * @return bool True if the user is in the admin group, false otherwise.
	 */
	private function isCurrentUserAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return (bool)$this->groupManager->isAdmin($user->getUID());
	}//end isCurrentUserAdmin()

	/**
	 * Resolve a soft-deleted object's schema and check the caller has the
	 * required action permission.
	 *
	 * Refuses the call (returns false) when:
	 *  - no user is authenticated, OR
	 *  - the object lacks a resolvable register/schema context, OR
	 *  - PermissionHandler denies the action for the caller.
	 *
	 * Admin users always pass. This mirrors the fail-closed write-RBAC
	 * pattern from #1949: when register/schema context cannot be derived,
	 * the destructive operation is refused.
	 *
	 * @param ObjectEntity $object The soft-deleted object being acted on.
	 * @param string $action The action to authorize ('delete'|'update').
	 *
	 * @return bool True if the caller may perform the action on this object.
	 */
	private function userMayActOnDeletedObject(ObjectEntity $object, string $action): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		// Admin bypass.
		if ($this->isCurrentUserAdmin() === true) {
			return true;
		}

		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			// Fail-closed: cannot resolve schema, refuse.
			return false;
		}

		try {
			$schema = $this->schemaMapper->find((int)$schemaId);
		} catch (\Throwable $e) {
			return false;
		}

		try {
			return $this->permissionHandler->hasPermission(
				schema: $schema,
				action: $action,
				userId: $user->getUID(),
				objectOwner: $object->getOwner(),
				_rbac: true,
				object: $object
			);
		} catch (\Throwable $e) {
			return false;
		}
	}//end userMayActOnDeletedObject()

	/**
	 * Resolve a soft-deleted object's schema, or null when it cannot be found.
	 *
	 * @param ObjectEntity $object The object whose schema to resolve.
	 *
	 * @return Schema|null The schema, or null when it cannot be resolved.
	 */
	private function resolveSchema(ObjectEntity $object): ?Schema {
		$schemaId = $object->getSchema();
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find((int)$schemaId);
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveSchema()

	/**
	 * Refuse a purge when the object is a legally retained archival record.
	 *
	 * `DELETE /api/objects/{register}/{schema}/{id}` rejects a delete on an
	 * archival schema with 403 SCHEMA_ARCHIVAL_IMMUTABLE
	 * ({@see \OCA\OpenRegister\Service\ObjectService::deleteObject()}). Purging
	 * is strictly more destructive than deleting, so it answers on the same
	 * terms, from the same definition ({@see Schema::hasArchivalAnnotation()}) —
	 * otherwise the trash is a second door onto the records the first door
	 * exists to protect.
	 *
	 * Fails CLOSED: an object whose schema cannot be resolved is refused, since
	 * an unresolvable schema is exactly the case where the annotation cannot be
	 * read and the row might be retained.
	 *
	 * @param ObjectEntity $object The object being purged.
	 *
	 * @return ArchivalImmutableException|null The refusal, or null when the purge may proceed.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	private function archivalRefusal(ObjectEntity $object): ?ArchivalImmutableException {
		$schema = $this->resolveSchema(object: $object);
		if ($schema === null) {
			return new ArchivalImmutableException(
				schemaIdentifier: (string)($object->getSchema() ?? 'unknown'),
				operation: 'purge'
			);
		}

		if ($schema->hasArchivalAnnotation() === false) {
			return null;
		}

		return new ArchivalImmutableException(
			schemaIdentifier: ($schema->getSlug() ?? (string)$schema->getId()),
			operation: 'purge'
		);
	}//end archivalRefusal()

	/**
	 * Helper method to extract request parameters for deleted objects
	 *
	 * @return array Request parameters including pagination and filters
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function extractRequestParameters(): array {
		$params = $this->request->getParams();

		// Extract pagination parameters.
		$limit = (int)($params['limit'] ?? $params['_limit'] ?? 20);

		$offset = null;
		if (($params['offset'] ?? null) !== null) {
			$offset = (int)$params['offset'];
		} elseif (($params['_offset'] ?? null) !== null) {
			$offset = (int)$params['_offset'];
		}

		$page = null;
		if (($params['page'] ?? null) !== null) {
			$page = (int)$params['page'];
		} elseif (($params['_page'] ?? null) !== null) {
			$page = (int)$params['_page'];
		}

		// If we have a page but no offset, calculate the offset.
		if ($page !== null && $offset === null) {
			$offset = ($page - 1) * $limit;
		}

		// Extract search parameter.
		$search = $params['search'] ?? $params['_search'] ?? null;

		// Extract sort parameters.
		$sort = [];
		if (($params['sort'] ?? null) !== null || (($params['_sort'] ?? null) !== null) === true) {
			$sortField = $params['sort'] ?? $params['_sort'] ?? 'updated';
			$sortOrder = $params['order'] ?? $params['_order'] ?? 'DESC';
			$sort[$sortField] = $sortOrder;
		}

		if (empty($sort) === true) {
			// Default sort by updated (last modified) which includes soft delete time.
			// Note: Cannot sort by 'deleted' directly as it's a JSON column in PostgreSQL.
			$sort['updated'] = 'DESC';
		}

		// Filter out special parameters and system fields.
		$filters = array_filter(
			$params,
			function ($key) {
				return !in_array(
					$key,
					[
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
					]
				);
			},
			ARRAY_FILTER_USE_KEY
		);

		return [
			'limit' => $limit,
			'offset' => $offset,
			'page' => $page,
			'filters' => $filters,
			'sort' => $sort,
			'search' => $search,
		];
	}//end extractRequestParameters()

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
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	private function withWindows(array $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			$row = $object->jsonSerialize();
			$window = $this->windowService->windowFor(
				object: $object,
				schema: $this->resolveSchema(object: $object)
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

		try {
			// Objects live in per-register/schema magic tables, so there is no
			// single table for searchObjectsPaginated() to query without a
			// register/schema context — it always fell through to an empty
			// result. Scan every magic table for soft-deleted rows directly.
			$deletedObjects = $this->objectEntityMapper->findDeletedAcrossAllMagicTables(
				limit: $params['limit'],
				offset: $params['offset']
			);
			$total = $this->objectEntityMapper->countDeletedAcrossAllMagicTables();

			// Calculate pagination.
			$pages = 1;
			if (($params['limit'] ?? null) !== null && ($params['limit'] > 0) === true) {
				$pages = ceil($total / $params['limit']);
			}

			return new JSONResponse(
				data: [
					'results' => $this->withWindows(objects: array_values($deletedObjects)),
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
			// the dedicated cross-table count is required.
			$totalDeleted = $this->objectEntityMapper->countDeletedAcrossAllMagicTables();

			// Get deleted today count.
			$today = (new DateTime())->format('Y-m-d');
			$deletedToday = $this->objectEntityMapper->countAll(
				_filters: [
					'@self.deleted' => 'IS NOT NULL',
					'@self.deleted.deleted' => '>=' . $today,
				],
			);

			// Get deleted this week count.
			$weekAgo = (new DateTime())->modify('-7 days')->format('Y-m-d');
			$deletedThisWeek = $this->objectEntityMapper->countAll(
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
		if ($this->isCurrentUserAdmin() === false) {
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
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with restore result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function restore(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			$object = $this->objectEntityMapper->find($id, null, null, true);

			if ($object->isSoftDeleted() === false) {
				return new JSONResponse(
					data: [
						'error' => 'Object is not deleted',
					],
					statusCode: 400
				);
			}

			// Restoring is a write. `restoreMultiple()` has gated on `update`
			// since the wave-3 C4 finding; the single-object endpoint beside it
			// never did, so the bulk door was locked and the single door was
			// open.
			if ($this->userMayActOnDeletedObject(object: $object, action: 'update') === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to restore this object'],
					statusCode: 403
				);
			}

			$window = $this->windowService->windowFor(
				object: $object,
				schema: $this->resolveSchema(object: $object)
			);

			// Restore via MagicMapper: objects live in per-register/schema magic
			// tables, NOT the legacy generic `openregister_objects` table. The
			// old direct `UPDATE openregister_objects` matched zero rows, so the
			// call reported success but never un-deleted the object.
			$this->objectEntityMapper->restoreObject(uuid: $id);

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
	 * Record a restore with the actor who made it.
	 *
	 * Failure is logged into the response path rather than thrown: a restore
	 * that succeeded must not be reported as failed because its record could
	 * not be written. That is the opposite trade-off to a DESTRUCTION, which
	 * refuses when unrecordable, and the difference is that a restore is
	 * reversible and a destruction is not.
	 *
	 * @param ObjectEntity $object The object restored.
	 * @param \OCA\OpenRegister\Service\Deletion\DeletionWindow|null $window The window it was restored inside.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	private function recordRestore(ObjectEntity $object, mixed $window): void {
		$user = $this->userSession->getUser();
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
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: 'object.restored',
				context: $context,
				actorId: $actor,
				actorName: $actorName
			);
		} catch (\Throwable $e) {
			// Swallowed on purpose, see the docblock.
		}
	}//end recordRestore()

	/**
	 * Restore multiple deleted objects
	 *
	 * Each soft-deleted object is gated through PermissionHandler with the
	 * `update` action against the object's resolved schema. Admins bypass.
	 * Objects whose schema cannot be resolved or for which the caller lacks
	 * `update` permission are skipped (counted as `failed`) so a partial
	 * cross-tenant bulk restore cannot succeed silently. This closes the
	 * wave-3 C4 finding (no RBAC on `restoreMultiple`).
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with multiple restore result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function restoreMultiple(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		$ids = $this->request->getParam('ids', []);

		if (empty($ids) === true) {
			return new JSONResponse(
				data: [
					'error' => 'No object IDs provided',
				],
				statusCode: 400
			);
		}

		try {
			// Resolve across ALL magic tables, including deleted rows. findAll()
			// without register/schema context returns [] (it cannot know which
			// magic table to query), so a UUID-keyed cross-table lookup is
			// required to actually find the soft-deleted objects.
			$objects = $this->objectEntityMapper->findMultipleAcrossAllMagicTables(
				uuids: $ids,
				includeDeleted: true
			);

			// Track results.
			$restored = 0;
			$failed = 0;
			$foundIds = [];

			// Process found objects.
			foreach ($objects as $object) {
				$foundIds[] = $object->getUuid();

				try {
					if ($object->isSoftDeleted() === false) {
						// Object exists but is not deleted.
						$failed++;
						continue;
					}

					// Per-object RBAC gate: caller must have `update`
					// permission on the resolved schema (admins bypass).
					// Cross-tenant restores are silently dropped (counted
					// as failed) rather than aborting the whole batch.
					if ($this->userMayActOnDeletedObject(object: $object, action: 'update') === false) {
						$failed++;
						continue;
					}

					// Restore via the magic-table-aware path (writes _deleted=NULL
					// on the correct per-register/schema table).
					$this->objectEntityMapper->restoreObject(uuid: (string)$object->getUuid());
					$restored++;
				} catch (\Exception $e) {
					$failed++;
				}//end try
			}//end foreach

			// Count objects that were requested but not found in database.
			$notFound = count(array_diff($ids, $foundIds));
			$failed += $notFound;

			return new JSONResponse(
				data: [
					'success' => true,
					'restored' => $restored,
					'failed' => $failed,
					'notFound' => $notFound,
					'message' => $this->formatRestoreMessage(restored: $restored, failed: $failed, notFound: $notFound),
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'Failed to restore objects: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end restoreMultiple()

	/**
	 * Permanently delete an object
	 *
	 * Gated through PermissionHandler with the `delete` action against the
	 * resolved schema (admins bypass). When register/schema context cannot
	 * be derived the call is refused fail-closed. This closes the wave-3 C4
	 * finding (no RBAC on `destroy`).
	 *
	 * @param string $id The ID or UUID of the object to permanently delete
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with deletion result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroy(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			$object = $this->objectEntityMapper->find(identifier: $id, register: null, schema: null, includeDeleted: true);

			// An archival record is refused here on the same terms the normal
			// delete path refuses it. Answered BEFORE the trash check, so a
			// caller holding a live archival record is told the record is
			// immutable rather than "not deleted yet" — which would read as an
			// invitation to soft-delete it first and come back.
			$refusal = $this->archivalRefusal(object: $object);
			if ($refusal !== null) {
				return new JSONResponse(
					data: $refusal->toResponseBody(),
					statusCode: 403
				);
			}

			// A purge empties the TRASH. `getDeleted() === null` never answered
			// that question — the property defaults to `[]` and a live row keeps
			// that default — so this guard let every live object through and
			// destroyed it. See ObjectEntity::isSoftDeleted().
			if ($object->isSoftDeleted() === false) {
				return new JSONResponse(
					data: [
						'error' => 'Object is not deleted',
					],
					statusCode: 400
				);
			}

			$schema = $this->resolveSchema(object: $object);

			// DESTROYING IS A RIGHT, NOT AN ADMIN CHECK. The refusal names the
			// rule that refused, because "Forbidden" with no rule leaves an
			// operator guessing about a destructive act.
			$refusal = $this->destroyRightService->refusalFor(object: $object, schema: $schema);
			if ($refusal !== null) {
				return new JSONResponse(
					data: $refusal->toResponseBody(),
					statusCode: $refusal->getStatusCode()
				);
			}

			// A HOLD OUTRANKS BOTH CLOCKS, and two clocks that disagree refuse
			// rather than pick a winner in silence.
			$clockRefusal = $this->clockService->refusalFor(object: $object);
			if ($clockRefusal !== null) {
				return new JSONResponse(
					data: $clockRefusal->toResponseBody(),
					statusCode: $clockRefusal->getStatusCode()
				);
			}

			// THE WINDOW IS A REFUSAL, NOT DECORATION. Inside it the object can
			// still come back, so destroying it needs the window waived
			// explicitly, and the refusal says how long is left.
			$window = $this->windowService->windowFor(object: $object, schema: $schema);
			$force = filter_var($this->request->getParam('force', false), FILTER_VALIDATE_BOOLEAN);
			if ($window !== null && $window->hasLapsed() === false && $force === false) {
				return new JSONResponse(
					data: [
						'error' => 'DESTRUCTION_REFUSED',
						'rule' => 'recovery-window-open',
						'message' => 'This object can still be restored until '
							. $window->destroyableFrom()->format('Y-m-d') . '. Destroying it before then '
							. 'requires the window to be waived explicitly.',
						'deletionWindow' => $window->toArray(),
					],
					statusCode: 409
				);
			}

			// The scope is previewed, then destroyed, then reported. An
			// unclear scope refuses before anything is touched.
			try {
				$scopeReport = $this->scopeService->destroy(object: $object, schema: $schema);
				$record = $this->destructionRecorder->record(
					object: $object,
					scope: $scopeReport,
					rule: 'destroy-right-granted',
					context: [
						'deletionWindow' => $window?->toArray(),
						'windowWaived' => ($force === true),
					]
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
			$this->objectEntityMapper->delete($object);

			return new JSONResponse(
				data: [
					'success' => true,
					'message' => 'Object permanently deleted',
					'destruction' => $record,
				]
			);
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
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function destructionPreview(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			$object = $this->objectEntityMapper->find(identifier: $id, register: null, schema: null, includeDeleted: true);
			$schema = $this->resolveSchema(object: $object);

			$refusal = $this->destroyRightService->refusalFor(object: $object, schema: $schema);
			if ($refusal !== null) {
				return new JSONResponse(
					data: $refusal->toResponseBody(),
					statusCode: $refusal->getStatusCode()
				);
			}

			$window = $this->windowService->windowFor(object: $object, schema: $schema);

			return new JSONResponse(
				data: [
					'objectUuid' => (string)$object->getUuid(),
					'preview' => $this->scopeService->preview(object: $object, schema: $schema),
					'deletionWindow' => $window?->toArray(),
					'clocks' => $this->clockService->clocksFor(object: $object),
				]
			);
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
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function destructionRecord(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		try {
			$records = $this->auditTrailMapper->findForObjectByAction(
				objectUuid: $id,
				actions: [DestructionScope::DESTRUCTION_ACTION]
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
	 * Each soft-deleted object is gated through PermissionHandler with the
	 * `delete` action against the object's resolved schema. Admins bypass.
	 * Objects whose schema cannot be resolved or for which the caller lacks
	 * `delete` permission are skipped (counted as `failed`) so a partial
	 * cross-tenant bulk wipe cannot succeed silently. This closes the
	 * wave-3 C4 finding (no RBAC on `destroyMultiple`).
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with multiple deletion result
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroyMultiple(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['error' => 'Not authenticated'],
				statusCode: 401
			);
		}

		$ids = $this->request->getParam('ids', []);

		if (empty($ids) === true) {
			return new JSONResponse(
				data: [
					'error' => 'No object IDs provided',
				],
				statusCode: 400
			);
		}

		try {
			// Resolve across ALL magic tables, including deleted rows. findAll()
			// without register/schema context returns [], so a UUID-keyed
			// cross-table lookup is required to find the soft-deleted objects.
			$objects = $this->objectEntityMapper->findMultipleAcrossAllMagicTables(
				uuids: $ids,
				includeDeleted: true
			);

			// Track results.
			$deleted = 0;
			$failed = 0;
			$foundIds = [];

			// Process found objects.
			foreach ($objects as $object) {
				$foundIds[] = $object->getUuid();

				try {
					// An archival record is never purged, in bulk or singly.
					// Counted as failed rather than aborting the batch, so one
					// retained row does not strand the rest of the cleanup.
					if ($this->archivalRefusal(object: $object) !== null) {
						$failed++;
						continue;
					}

					if ($object->isSoftDeleted() === false) {
						// Object exists but is not deleted.
						$failed++;
						continue;
					}

					// Per-object RBAC gate: caller must have `delete`
					// permission on the resolved schema (admins bypass).
					if ($this->userMayActOnDeletedObject(object: $object, action: 'delete') === false) {
						$failed++;
						continue;
					}

					$this->objectEntityMapper->delete($object);
					$deleted++;
				} catch (\Exception $e) {
					$failed++;
				}
			}//end foreach

			// Count objects that were requested but not found in database.
			$notFound = count(array_diff($ids, $foundIds));
			$failed += $notFound;

			return new JSONResponse(
				data: [
					'success' => true,
					'deleted' => $deleted,
					'failed' => $failed,
					'notFound' => $notFound,
					'message' => $this->formatDeleteMessage(deleted: $deleted, failed: $failed, notFound: $notFound),
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(
				data: [
					'error' => 'Failed to permanently delete objects: ' . $e->getMessage(),
				],
				statusCode: 500
			);
		}//end try
	}//end destroyMultiple()

	/**
	 * Format restore message.
	 *
	 * @param int $restored Number of restored objects.
	 * @param int $failed Number of failed restorations.
	 * @param int $notFound Number of objects not found.
	 *
	 * @return string Formatted message.
	 */
	private function formatRestoreMessage(int $restored, int $failed, int $notFound): string {
		$message = "Restored {$restored} objects, {$failed} failed";
		if ($notFound > 0) {
			$message .= " ({$notFound} not found)";
		}

		return $message;
	}//end formatRestoreMessage()

	/**
	 * Format delete message.
	 *
	 * @param int $deleted Number of deleted objects.
	 * @param int $failed Number of failed deletions.
	 * @param int $notFound Number of objects not found.
	 *
	 * @return string Formatted message.
	 */
	private function formatDeleteMessage(int $deleted, int $failed, int $notFound): string {
		$message = "Permanently deleted {$deleted} objects, {$failed} failed";
		if ($notFound > 0) {
			$message .= " ({$notFound} not found)";
		}

		return $message;
	}//end formatDeleteMessage()
}//end class
