<?php
/**
 * Class DeletedController
 *
 * Controller for managing soft deleted objects in the OpenRegister app.
 * Provides functionality for listing, filtering, restoring, and permanently deleting objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Class DeletedController
 *
 * Controller for managing soft deleted objects
 */
class DeletedController extends Controller
{


    /**
     * Constructor for the DeletedController
     *
     * @param string             $appName            The name of the app
     * @param IRequest           $request            The request object
     * @param ObjectEntityMapper $objectEntityMapper The object entity mapper
     * @param RegisterMapper     $registerMapper     The register mapper
     * @param SchemaMapper       $schemaMapper       The schema mapper
     * @param ObjectService      $objectService      The object service
     * @param IUserSession       $userSession        The user session
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Helper method to extract request parameters for deleted objects
     *
     * @return array Configuration array containing pagination, filters, and search parameters
     */
    private function extractRequestParameters(): array
    {
        $params = $this->request->getParams();

        // Extract pagination parameters.
        $limit = (int) ($params['limit'] ?? $params['_limit'] ?? 20);

        $offset = null;
        if (isset($params['offset']) === true) {
            $offset = (int) $params['offset'];
        } else if (isset($params['_offset']) === true) {
            $offset = (int) $params['_offset'];
        }

        $page = null;
        if (isset($params['page']) === true) {
            $page = (int) $params['page'];
        } else if (isset($params['_page']) === true) {
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
        if (isset($params['sort']) === true || isset($params['_sort']) === true) {
            $sortField        = $params['sort'] ?? $params['_sort'] ?? 'deleted';
            $sortOrder        = $params['order'] ?? $params['_order'] ?? 'DESC';
            $sort[$sortField] = $sortOrder;
        } else {
            $sort['deleted'] = 'DESC';
            // Default sort by deletion date.
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
     * @return JSONResponse A JSON response containing the deleted objects
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        $params = $this->extractRequestParameters();

        try {
            // Get deleted objects using the mapper with includeDeleted = true and filter for only deleted objects.
            $params['filters']['@self.deleted'] = 'IS NOT NULL';

            $objects = $this->objectEntityMapper->findAll(
                limit: $params['limit'],
                offset: $params['offset'],
                filters: $params['filters'],
                sort: $params['sort'],
                search: $params['search'],
                includeDeleted: true
            // Include deleted objects.
            );

            // Filter to only show actually deleted objects (extra safety).
            $deletedObjects = array_filter(
                    $objects,
                    function ($object) {
                        return $object->getDeleted() !== null;
                    }
            );

            // Get total count for pagination.
            $total = $this->objectEntityMapper->countAll(
                filters: $params['filters'],
                search: $params['search'],
                includeDeleted: true
            );

            // Calculate pagination.
            $pages = 1;
            if (isset($params['limit']) === true && $params['limit'] > 0) {
                $pages = ceil($total / $params['limit']);
            }

            return new JSONResponse(
                    data: [
                        'results' => array_values($deletedObjects), statusCode:
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
                        'error' => 'Failed to retrieve deleted objects: '.$e->getMessage(), statusCode:
                    ],
                    500
                    );
        }//end try

    }//end index()


    /**
     * Get statistics for deleted objects
     *
     * @return JSONResponse A JSON response containing deletion statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function statistics(): JSONResponse
    {
        try {
            // Get total deleted count.
            $totalDeleted = $this->objectEntityMapper->countAll(
                filters: ['@self.deleted' => 'IS NOT NULL'],
                includeDeleted: true
            );

            // Get deleted today count.
            $today        = (new \DateTime())->format('Y-m-d');
            $deletedToday = $this->objectEntityMapper->countAll(
                filters: [
                    '@self.deleted'         => 'IS NOT NULL',
                    '@self.deleted.deleted' => '>='.$today,
                ],
                includeDeleted: true
            );

            // Get deleted this week count.
            $weekAgo         = (new \DateTime())->modify('-7 days')->format('Y-m-d');
            $deletedThisWeek = $this->objectEntityMapper->countAll(
                filters: [
                    '@self.deleted'         => 'IS NOT NULL',
                    '@self.deleted.deleted' => '>='.$weekAgo,
                ],
                includeDeleted: true
            );

            // Calculate oldest deletion (placeholder for now).
            $oldestDays = 0;
            // TODO: Calculate actual oldest deletion.
            return new JSONResponse(
                    data: [
                        'totalDeleted'    => $totalDeleted, statusCode:
            'deletedToday'    => $deletedToday,
                        'deletedThisWeek' => $deletedThisWeek,
                        'oldestDays'      => $oldestDays,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'error' => 'Failed to get statistics: '.$e->getMessage(), statusCode:
                    ],
                    500
                    );
        }//end try

    }//end statistics()


    /**
     * Get top deleters statistics
     *
     * @return JSONResponse A JSON response containing top deleters data
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
                        'error' => 'Failed to get top deleters: '.$e->getMessage(), statusCode:
                    ],
                    500
                    );
        }

    }//end topDeleters()


    /**
     * Restore a deleted object
     *
     * @param string $id The ID or UUID of the object to restore
     *
     * @return JSONResponse A JSON response indicating success or failure
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function restore(string $id): JSONResponse
    {
        try {
            $object = $this->objectEntityMapper->find($id, null, null, true);

            if ($object->getDeleted() === null) {
                return new JSONResponse(
                        data: [
                            'error' => 'Object is not deleted', statusCode:
                        ],
                        400
                        );
            }

            // Clear the deleted status.
            $object->setDeleted(null);
            $this->objectEntityMapper->update($object, true);

            return new JSONResponse(
                    data: [
                        'success' => true, statusCode:
            'message' => 'Object restored successfully',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'error' => 'Failed to restore object: '.$e->getMessage(), statusCode:
                    ],
                    500
                    );
        }//end try

    }//end restore()


    /**
     * Restore multiple deleted objects
     *
     * TODO: This function is unsafe as it doesn't filter by register/schema.
     * In the future, add register and schema filtering to mass operations
     * to prevent cross-register restoring.
     *
     * @return JSONResponse A JSON response with restoration results
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function restoreMultiple(): JSONResponse
    {
        $ids = $this->request->getParam('ids', []);

        if (empty($ids) === true) {
            return new JSONResponse(
                    data: [
                        'error' => 'No object IDs provided', statusCode:
                    ],
                    400
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
                includeDeleted: true
            );

            // Track results.
            $restored = 0;
            $failed   = 0;
            $foundIds = [];

            // Process found objects.
            foreach ($objects as $object) {
                $foundIds[] = $object->getId();

                try {
                    if ($object->getDeleted() !== null) {
                        $object->setDeleted(null);
                        $this->objectEntityMapper->update($object, true);
                        $restored++;
                    } else {
                        // Object exists but is not deleted.
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                }
            }

            // Count objects that were requested but not found in database.
            $notFound = count(array_diff($ids, $foundIds));
            $failed  += $notFound;

            return new JSONResponse(
                    data: [
                        'success'  => true, statusCode:
            'restored' => $restored,
                        'failed'   => $failed,
                        'notFound' => $notFound,
                        'message'  => $this->formatRestoreMessage($restored, $failed, $notFound),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'error' => 'Failed to restore objects: '.$e->getMessage(), statusCode:
                    ],
                    500
                    );
        }//end try

    }//end restoreMultiple()


    /**
     * Permanently delete an object
     *
     * @param string $id The ID or UUID of the object to permanently delete
     *
     * @return JSONResponse A JSON response indicating success or failure
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $object = $this->objectEntityMapper->find($id, null, null, true);

            if ($object->getDeleted() === null) {
                return new JSONResponse(
                        data: [
                            'error' => 'Object is not deleted', statusCode:
                        ],
                        400
                        );
            }

            // Permanently delete the object.
            $this->objectEntityMapper->delete($object);

            return new JSONResponse(
                    data: [
                        'success' => true, statusCode:
            'message' => 'Object permanently deleted',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'error' => 'Failed to permanently delete object: '.$e->getMessage(), statusCode:
                    ],
                    500
                    );
        }//end try

    }//end destroy()


    /**
     * Permanently delete multiple objects
     *
     * TODO: This function is unsafe as it doesn't filter by register/schema.
     * In the future, add register and schema filtering to mass operations
     * to prevent cross-register deleting.
     *
     * @return JSONResponse A JSON response with deletion results
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroyMultiple(): JSONResponse
    {
        $ids = $this->request->getParam('ids', []);

        if (empty($ids) === true) {
            return new JSONResponse(
                    data: [
                        'error' => 'No object IDs provided', statusCode:
                    ],
                    400
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
                includeDeleted: true
            );

            // Track results.
            $deleted  = 0;
            $failed   = 0;
            $foundIds = [];

            // Process found objects.
            foreach ($objects as $object) {
                $foundIds[] = $object->getId();

                try {
                    if ($object->getDeleted() !== null) {
                        $this->objectEntityMapper->delete($object);
                        $deleted++;
                    } else {
                        // Object exists but is not deleted.
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                }
            }

            // Count objects that were requested but not found in database.
            $notFound = count(array_diff($ids, $foundIds));
            $failed  += $notFound;

            return new JSONResponse(
                    data: [
                        'success'  => true, statusCode:
            'deleted'  => $deleted,
                        'failed'   => $failed,
                        'notFound' => $notFound,
                        'message'  => $this->formatDeleteMessage($deleted, $failed, $notFound),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'error' => 'Failed to permanently delete objects: '.$e->getMessage(), statusCode:
                    ],
                    500
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
