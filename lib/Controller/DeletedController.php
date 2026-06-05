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
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-28
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-30
 * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-31
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-28
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-30
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-31
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Db\MagicMapper;
<<<<<<< HEAD
=======
use OCA\OpenRegister\Db\ObjectEntity;
>>>>>>> origin/development
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class DeletedController
 *
 * Controller for managing soft deleted objects
 *
 * @psalm-suppress UnusedClass
 */

class DeletedController extends Controller
{
    /**
     * Constructor for the DeletedController
     *
<<<<<<< HEAD
     * @param string         $appName            The name of the app
     * @param IRequest       $request            The request object
     * @param MagicMapper    $objectEntityMapper The object entity mapper
     * @param RegisterMapper $registerMapper     The register mapper
     * @param SchemaMapper   $schemaMapper       The schema mapper
     * @param ObjectService  $objectService      The object service
     * @param IUserSession   $userSession        The user session
     * @param IGroupManager  $groupManager       The group manager for admin checks
=======
     * @param string            $appName            The name of the app
     * @param IRequest          $request            The request object
     * @param MagicMapper       $objectEntityMapper The object entity mapper
     * @param RegisterMapper    $registerMapper     The register mapper
     * @param SchemaMapper      $schemaMapper       The schema mapper
     * @param ObjectService     $objectService      The object service
     * @param IUserSession      $userSession        The user session
     * @param IGroupManager     $groupManager       The group manager for admin checks
     * @param PermissionHandler $permissionHandler  Handler for per-schema RBAC checks
>>>>>>> origin/development
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MagicMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
<<<<<<< HEAD
        private readonly IGroupManager $groupManager
=======
        private readonly IGroupManager $groupManager,
        private readonly PermissionHandler $permissionHandler
>>>>>>> origin/development
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Check if the current user is an admin
     *
     * @return bool True if the user is in the admin group, false otherwise.
     */
    private function isCurrentUserAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

<<<<<<< HEAD
        return $this->groupManager->isAdmin($user->getUID());
    }//end isCurrentUserAdmin()

    /**
=======
        return (bool) $this->groupManager->isAdmin($user->getUID());
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
     * @param string       $action The action to authorize ('delete'|'update').
     *
     * @return bool True if the caller may perform the action on this object.
     */
    private function userMayActOnDeletedObject(ObjectEntity $object, string $action): bool
    {
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
            $schema = $this->schemaMapper->find((int) $schemaId);
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
>>>>>>> origin/development
     * Helper method to extract request parameters for deleted objects
     *
     * @return array Request parameters including pagination and filters
     *
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function extractRequestParameters(): array
    {
        $params = $this->request->getParams();

        // Extract pagination parameters.
        $limit = (int) ($params['limit'] ?? $params['_limit'] ?? 20);

        $offset = null;
        if (($params['offset'] ?? null) !== null) {
            $offset = (int) $params['offset'];
        } else if (($params['_offset'] ?? null) !== null) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (($params['page'] ?? null) !== null) {
            $page = (int) $params['page'];
        } else if (($params['_page'] ?? null) !== null) {
            $page = (int) $params['_page'];
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
            $sortField        = $params['sort'] ?? $params['_sort'] ?? 'updated';
            $sortOrder        = $params['order'] ?? $params['_order'] ?? 'DESC';
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
            'limit'   => $limit,
            'offset'  => $offset,
            'page'    => $page,
            'filters' => $filters,
            'sort'    => $sort,
            'search'  => $search,
        ];
    }//end extractRequestParameters()

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
     *     results?: list<\OCA\OpenRegister\Db\ObjectEntity>, total?: int,
     *     page?: int, pages?: 1|float, limit?: int|null, offset?: int|null},
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-30
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-30
     */
    public function index(): JSONResponse
    {
        $params = $this->extractRequestParameters();

        try {
            // Use searchObjectsPaginated with @self.deleted filter to find deleted objects.
            // Build query array with filter for deleted objects.
            $query = [
                '@self.deleted' => 'IS NOT NULL',
                '_limit'        => $params['limit'],
                '_offset'       => $params['offset'],
                '_order'        => $params['sort'],
            ];

            // Merge any additional filters from request.
            foreach ($params['filters'] as $key => $value) {
                if ($key !== '@self.deleted') {
                    $query[$key] = $value;
                }
            }

            // Determine if current user is admin and disable multitenancy if so.
            $isAdmin = $this->isCurrentUserAdmin();

            // Use ObjectService to search for deleted objects with deleted=true to include them.
            $result = $this->objectService->searchObjectsPaginated(
                query: $query,
                deleted: true,
                // This tells the service to include deleted objects in the search.
                _multitenancy: !$isAdmin
                // Disable multitenancy for admins so they can see all deleted objects.
            );

            $deletedObjects = $result['results'] ?? [];
            $total          = $result['total'] ?? 0;

            // Calculate pagination.
            $pages = 1;
            if (($params['limit'] ?? null) !== null && ($params['limit'] > 0) === true) {
                $pages = ceil($total / $params['limit']);
            }

            return new JSONResponse(
                data: [
                    'results' => array_values($deletedObjects),
                    'total'   => $total,
                    'page'    => $params['page'] ?? 1,
                    'pages'   => $pages,
                    'limit'   => $params['limit'],
                    'offset'  => $params['offset'],
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to retrieve deleted objects: '.$e->getMessage(),
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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-16
     */
    public function statistics(): JSONResponse
    {
        try {
            // Get total deleted count.
            $totalDeleted = $this->objectEntityMapper->countAll(
                _filters: ['@self.deleted' => 'IS NOT NULL'],
            );

            // Get deleted today count.
            $today        = (new DateTime())->format('Y-m-d');
            $deletedToday = $this->objectEntityMapper->countAll(
                _filters: [
                    '@self.deleted'         => 'IS NOT NULL',
                    '@self.deleted.deleted' => '>='.$today,
                ],
            );

            // Get deleted this week count.
            $weekAgo         = (new DateTime())->modify('-7 days')->format('Y-m-d');
            $deletedThisWeek = $this->objectEntityMapper->countAll(
                _filters: [
                    '@self.deleted'         => 'IS NOT NULL',
                    '@self.deleted.deleted' => '>='.$weekAgo,
                ],
            );

            // Calculate oldest deletion (placeholder for now).
            $oldestDays = 0;
            // TODO: Calculate actual oldest deletion.
            return new JSONResponse(
                data: [
                    'totalDeleted'    => $totalDeleted,
                    'deletedToday'    => $deletedToday,
                    'deletedThisWeek' => $deletedThisWeek,
                    'oldestDays'      => $oldestDays,
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to get statistics: '.$e->getMessage(),
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
     * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-object-data/tasks.md#task-16
     */
    public function topDeleters(): JSONResponse
    {
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
                    'error' => 'Failed to get top deleters: '.$e->getMessage(),
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
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-31
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-31
     */
    public function restore(string $id): JSONResponse
    {
        try {
            $object = $this->objectEntityMapper->find($id, null, null, true);

            if ($object->getDeleted() === null || $object->getDeleted() === []) {
                return new JSONResponse(
                    data: [
                        'error' => 'Object is not deleted',
                    ],
                    statusCode: 400
                );
            }

            // Clear the deleted status using direct SQL update.
            // Nextcloud Entity system has issues detecting array->null changes for JSON fields.
            $qb = $this->objectEntityMapper->getQueryBuilder();
            $qb->update('openregister_objects')
                ->set('deleted', $qb->createNamedParameter(null, \PDO::PARAM_NULL))
                ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($id)))
                ->executeStatement();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'Object restored successfully',
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to restore object: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end restore()

    /**
     * Restore multiple deleted objects
     *
<<<<<<< HEAD
     * TODO: This function is unsafe as it doesn't filter by register/schema.
     * In the future, add register and schema filtering to mass operations
     * to prevent cross-register restoring.
=======
     * Each soft-deleted object is gated through PermissionHandler with the
     * `update` action against the object's resolved schema. Admins bypass.
     * Objects whose schema cannot be resolved or for which the caller lacks
     * `update` permission are skipped (counted as `failed`) so a partial
     * cross-tenant bulk restore cannot succeed silently. This closes the
     * wave-3 C4 finding (no RBAC on `restoreMultiple`).
>>>>>>> origin/development
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with multiple restore result
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-31
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-31
     */
    public function restoreMultiple(): JSONResponse
    {
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
            // Use findAll for better database performance - single query instead of multiple.
            $objects = $this->objectEntityMapper->findAll(
                limit: null,
                offset: null,
                filters: [],
                searchConditions: [],
                searchParams: [],
                sort: [],
                search: null,
                ids: $ids,
                uses: null,
            );

            // Track results.
            $restored = 0;
            $failed   = 0;
            $foundIds = [];

            // Process found objects.
            foreach ($objects as $object) {
                $foundIds[] = $object->getId();

                try {
                    if ($object->getDeleted() === null) {
                        // Object exists but is not deleted.
                        $failed++;
                        continue;
                    }

<<<<<<< HEAD
=======
                    // Per-object RBAC gate: caller must have `update`
                    // permission on the resolved schema (admins bypass).
                    // Cross-tenant restores are silently dropped (counted
                    // as failed) rather than aborting the whole batch.
                    if ($this->userMayActOnDeletedObject(object: $object, action: 'update') === false) {
                        $failed++;
                        continue;
                    }

>>>>>>> origin/development
                    $object->setDeleted(null);
                    $this->objectEntityMapper->update(entity: $object);
                    $restored++;
                } catch (\Exception $e) {
                    $failed++;
                }//end try
            }//end foreach

            // Count objects that were requested but not found in database.
            $notFound = count(array_diff($ids, $foundIds));
            $failed  += $notFound;

            return new JSONResponse(
                data: [
                    'success'  => true,
                    'restored' => $restored,
                    'failed'   => $failed,
                    'notFound' => $notFound,
                    'message'  => $this->formatRestoreMessage(restored: $restored, failed: $failed, notFound: $notFound),
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to restore objects: '.$e->getMessage(),
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
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-28
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-28
     */
    public function destroy(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                data: ['error' => 'Not authenticated'],
                statusCode: 401
            );
        }

        try {
            $object = $this->objectEntityMapper->find(identifier: $id, register: null, schema: null, includeDeleted: true);

            if ($object->getDeleted() === null) {
                return new JSONResponse(
                    data: [
                        'error' => 'Object is not deleted',
                    ],
                    statusCode: 400
                );
            }

<<<<<<< HEAD
=======
            // Per-object RBAC gate: caller must have `delete` permission on
            // the resolved schema (admins bypass). Cross-tenant destructive
            // deletes are refused with 403 — no silent fail since this is a
            // single-object endpoint.
            if ($this->userMayActOnDeletedObject(object: $object, action: 'delete') === false) {
                return new JSONResponse(
                    data: ['error' => 'User does not have permission to permanently delete this object'],
                    statusCode: 403
                );
            }

>>>>>>> origin/development
            // Permanently delete the object.
            $this->objectEntityMapper->delete($object);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'Object permanently deleted',
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to permanently delete object: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end destroy()

    /**
     * Permanently delete multiple objects
     *
<<<<<<< HEAD
     * TODO: This function is unsafe as it doesn't filter by register/schema.
     * In the future, add register and schema filtering to mass operations
     * to prevent cross-register deleting.
=======
     * Each soft-deleted object is gated through PermissionHandler with the
     * `delete` action against the object's resolved schema. Admins bypass.
     * Objects whose schema cannot be resolved or for which the caller lacks
     * `delete` permission are skipped (counted as `failed`) so a partial
     * cross-tenant bulk wipe cannot succeed silently. This closes the
     * wave-3 C4 finding (no RBAC on `destroyMultiple`).
>>>>>>> origin/development
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with multiple deletion result
     *
     * @spec openspec/changes/retrofit-2026-04-30-annotate-openregister/tasks.md#task-28
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-28
     */
    public function destroyMultiple(): JSONResponse
    {
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
            // Use findAll for better database performance - single query instead of multiple.
            $objects = $this->objectEntityMapper->findAll(
                limit: null,
                offset: null,
                filters: [],
                searchConditions: [],
                searchParams: [],
                sort: [],
                search: null,
                ids: $ids,
                uses: null,
            );

            // Track results.
            $deleted  = 0;
            $failed   = 0;
            $foundIds = [];

            // Process found objects.
            foreach ($objects as $object) {
                $foundIds[] = $object->getId();

                try {
                    if ($object->getDeleted() === null) {
                        // Object exists but is not deleted.
                        $failed++;
                        continue;
                    }

<<<<<<< HEAD
=======
                    // Per-object RBAC gate: caller must have `delete`
                    // permission on the resolved schema (admins bypass).
                    if ($this->userMayActOnDeletedObject(object: $object, action: 'delete') === false) {
                        $failed++;
                        continue;
                    }

>>>>>>> origin/development
                    $this->objectEntityMapper->delete($object);
                    $deleted++;
                } catch (\Exception $e) {
                    $failed++;
                }
            }//end foreach

            // Count objects that were requested but not found in database.
            $notFound = count(array_diff($ids, $foundIds));
            $failed  += $notFound;

            return new JSONResponse(
                data: [
                    'success'  => true,
                    'deleted'  => $deleted,
                    'failed'   => $failed,
                    'notFound' => $notFound,
                    'message'  => $this->formatDeleteMessage(deleted: $deleted, failed: $failed, notFound: $notFound),
                ]
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'error' => 'Failed to permanently delete objects: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end destroyMultiple()

    /**
     * Format restore message.
     *
     * @param int $restored Number of restored objects.
     * @param int $failed   Number of failed restorations.
     * @param int $notFound Number of objects not found.
     *
     * @return string Formatted message.
     */
    private function formatRestoreMessage(int $restored, int $failed, int $notFound): string
    {
        $message = "Restored {$restored} objects, {$failed} failed";
        if ($notFound > 0) {
            $message .= " ({$notFound} not found)";
        }

        return $message;
    }//end formatRestoreMessage()

    /**
     * Format delete message.
     *
     * @param int $deleted  Number of deleted objects.
     * @param int $failed   Number of failed deletions.
     * @param int $notFound Number of objects not found.
     *
     * @return string Formatted message.
     */
    private function formatDeleteMessage(int $deleted, int $failed, int $notFound): string
    {
        $message = "Permanently deleted {$deleted} objects, {$failed} failed";
        if ($notFound > 0) {
            $message .= " ({$notFound} not found)";
        }

        return $message;
    }//end formatDeleteMessage()
}//end class
