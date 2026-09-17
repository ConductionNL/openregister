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
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Deletion\DeletionServiceBundle;
use OCA\OpenRegister\Service\Deletion\DeletionWindow;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
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
	 * @param IUserSession $userSession The user session
	 * @param AuditTrailMapper $auditTrailMapper Reads back a destruction record and records a restore
	 * @param DeletionServiceBundle $deletion The destruction-pipeline collaborators (window, right, scope, recorder, clock)
	 * @param DeletedObjectAuthorizer $authorizer Answers the authorization and schema-resolution questions
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly MagicMapper $objectEntityMapper,
		private readonly RegisterMapper $registerMapper,
		private readonly IUserSession $userSession,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly DeletionServiceBundle $deletion,
		private readonly DeletedObjectAuthorizer $authorizer,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

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
			$window = $this->deletion->window->windowFor(
				object: $object,
				schema: $this->authorizer->resolveSchema(object: $object)
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
		if ($this->authorizer->isCurrentUserAdmin() === false) {
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
			if ($this->authorizer->userMayActOnDeletedObject(object: $object, action: 'update') === false) {
				return new JSONResponse(
					data: ['error' => 'User does not have permission to restore this object'],
					statusCode: 403
				);
			}

			$window = $this->deletion->window->windowFor(
				object: $object,
				schema: $this->authorizer->resolveSchema(object: $object)
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
					if ($this->authorizer->userMayActOnDeletedObject(object: $object, action: 'update') === false) {
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

			// An archival record is refused BEFORE the trash check, so a caller
			// holding a live archival record is told the record is immutable
			// rather than "not deleted yet". Then a purge only empties the
			// trash, never a live object.
			$refusal = $this->refuseUndestroyable(object: $object);
			if ($refusal !== null) {
				return $refusal;
			}

			$schema = $this->authorizer->resolveSchema(object: $object);
			$window = $this->deletion->window->windowFor(object: $object, schema: $schema);
			$force = filter_var($this->request->getParam('force', false), FILTER_VALIDATE_BOOLEAN);

			// The right, both retention clocks and the open recovery window each
			// refuse in turn, naming the rule that refused.
			$refusal = $this->refuseDestruction(object: $object, schema: $schema, window: $window, force: $force);
			if ($refusal !== null) {
				return $refusal;
			}

			return $this->performDestruction(object: $object, schema: $schema, window: $window, force: $force);
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
		$refusal = $this->authorizer->archivalRefusal(object: $object);
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
	 * @param ObjectEntity $object The object being purged.
	 * @param Schema|null $schema The object's resolved schema.
	 * @param DeletionWindow|null $window The published recovery window.
	 * @param bool $force Whether the caller explicitly waived the window.
	 *
	 * @return JSONResponse|null The refusal, or null when the destruction may proceed.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function refuseDestruction(ObjectEntity $object, ?Schema $schema, ?DeletionWindow $window, bool $force): ?JSONResponse {
		$refusal = $this->deletion->destroyRight->refusalFor(object: $object, schema: $schema);
		if ($refusal !== null) {
			return new JSONResponse(
				data: $refusal->toResponseBody(),
				statusCode: $refusal->getStatusCode()
			);
		}

		$clockRefusal = $this->deletion->clock->refusalFor(object: $object);
		if ($clockRefusal !== null) {
			return new JSONResponse(
				data: $clockRefusal->toResponseBody(),
				statusCode: $clockRefusal->getStatusCode()
			);
		}

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

		return null;
	}//end refuseDestruction()

	/**
	 * Preview the scope, record the destruction, then carry it out.
	 *
	 * The record is written before the object is deleted, so a crash between
	 * them leaves an over-recorded destruction rather than an unrecorded one. A
	 * scope this instance cannot honour refuses before anything is touched.
	 *
	 * @param ObjectEntity $object The object being purged.
	 * @param Schema|null $schema The object's resolved schema.
	 * @param DeletionWindow|null $window The published recovery window.
	 * @param bool $force Whether the caller explicitly waived the window.
	 *
	 * @return JSONResponse The destruction record, or the scope refusal.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	private function performDestruction(ObjectEntity $object, ?Schema $schema, ?DeletionWindow $window, bool $force): JSONResponse {
		try {
			$scopeReport = $this->deletion->scope->destroy(object: $object, schema: $schema);
			$record = $this->deletion->recorder->record(
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
			$schema = $this->authorizer->resolveSchema(object: $object);

			$refusal = $this->deletion->destroyRight->refusalFor(object: $object, schema: $schema);
			if ($refusal !== null) {
				return new JSONResponse(
					data: $refusal->toResponseBody(),
					statusCode: $refusal->getStatusCode()
				);
			}

			$window = $this->deletion->window->windowFor(object: $object, schema: $schema);

			return new JSONResponse(
				data: [
					'objectUuid' => (string)$object->getUuid(),
					'preview' => $this->deletion->scope->preview(object: $object, schema: $schema),
					'deletionWindow' => $window?->toArray(),
					'clocks' => $this->deletion->clock->clocksFor(object: $object),
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
					if ($this->authorizer->archivalRefusal(object: $object) !== null) {
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
					if ($this->authorizer->userMayActOnDeletedObject(object: $object, action: 'delete') === false) {
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
