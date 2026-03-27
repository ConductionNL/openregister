<?php

/**
 * OpenRegister GDPR Entities Controller
 *
 * Controller for managing GDPR entities (detected PII) in the OpenRegister app.
 * Provides endpoints for listing, viewing, and managing detected entities
 * from text extraction and entity recognition.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GdprEntitiesController handles GDPR entity management operations
 *
 * Provides REST API endpoints for managing detected entities from
 * text extraction and entity recognition processes.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class GdprEntitiesController extends Controller
{
    /**
     * GdprEntitiesController constructor
     *
     * @param string               $appName              Application name
     * @param IRequest             $request              HTTP request object
     * @param GdprEntityMapper     $entityMapper         GDPR entity mapper
     * @param EntityRelationMapper $entityRelationMapper Entity relation mapper
     * @param IDBConnection        $db                   Database connection
     * @param LoggerInterface      $logger               Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GdprEntityMapper $entityMapper,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get all entities with optional filtering and pagination
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with entities list
     */
    public function index(): JSONResponse
    {
        try {
            $limit    = (int) $this->request->getParam('limit', 50);
            $offset   = (int) $this->request->getParam('offset', 0);
            $search   = $this->request->getParam('search', '');
            $type     = $this->request->getParam('type', '');
            $category = $this->request->getParam('category', '');

            // Build query for entities with relation count.
            $qb = $this->db->getQueryBuilder();

            // Subquery for relation count.
            $subQb = $this->db->getQueryBuilder();
            $subQb->select($subQb->func()->count('*'))
                ->from('openregister_entity_relations', 'r')
                ->where($subQb->expr()->eq('r.entity_id', 'e.id'));

            $qb->select(
                'e.id',
                'e.uuid',
                'e.type',
                'e.value',
                'e.category',
                'e.detected_at',
                'e.updated_at'
            )
                ->selectAlias($qb->createFunction('('.$subQb->getSQL().')'), 'relation_count')
                ->from('openregister_entities', 'e');

            // Apply filters.
            if ($search !== '') {
                $qb->andWhere(
                    $qb->expr()->iLike('e.value', $qb->createNamedParameter('%'.$search.'%'))
                );
            }

            if ($type !== '') {
                $qb->andWhere(
                    $qb->expr()->eq('e.type', $qb->createNamedParameter($type))
                );
            }

            if ($category !== '') {
                $qb->andWhere(
                    $qb->expr()->eq('e.category', $qb->createNamedParameter($category))
                );
            }

            // Get total count.
            $countQb = $this->db->getQueryBuilder();
            $countQb->select($countQb->func()->count('*', 'total'))
                ->from('openregister_entities', 'e');

            if ($search !== '') {
                $countQb->andWhere(
                    $countQb->expr()->iLike('e.value', $countQb->createNamedParameter('%'.$search.'%'))
                );
            }

            if ($type !== '') {
                $countQb->andWhere(
                    $countQb->expr()->eq('e.type', $countQb->createNamedParameter($type))
                );
            }

            if ($category !== '') {
                $countQb->andWhere(
                    $countQb->expr()->eq('e.category', $countQb->createNamedParameter($category))
                );
            }

            $countResult = $countQb->executeQuery();
            $total       = (int) $countResult->fetchOne();
            $countResult->closeCursor();

            // Apply pagination and ordering.
            $qb->orderBy('e.detected_at', 'DESC')
                ->setMaxResults($limit)
                ->setFirstResult($offset);

            $result   = $qb->executeQuery();
            $entities = [];

            while (($row = $result->fetch()) !== false) {
                $entities[] = [
                    'id'            => (int) $row['id'],
                    'uuid'          => $row['uuid'],
                    'type'          => $row['type'],
                    'value'         => $row['value'],
                    'category'      => $row['category'],
                    'detectedAt'    => $row['detected_at'],
                    'updatedAt'     => $row['updated_at'],
                    'relationCount' => (int) $row['relation_count'],
                ];
            }

            $result->closeCursor();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => $entities,
                    'count'   => $total,
                    'limit'   => $limit,
                    'offset'  => $offset,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[GdprEntitiesController] Failed to list entities',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to list entities: '.$e->getMessage(),
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end index()

    /**
     * Get a single entity by ID
     *
     * @param int $id Entity ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with entity details
     */
    public function show(int $id): JSONResponse
    {
        try {
            $entity = $this->entityMapper->find($id);

            // Get relations for this entity.
            $relations = $this->entityRelationMapper->findByEntityId($id);

            return new JSONResponse(
                data: [
                    'success'   => true,
                    'data'      => $entity->jsonSerialize(),
                    'relations' => array_map(fn($r) => $r->jsonSerialize(), $relations),
                ]
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Entity not found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[GdprEntitiesController] Failed to get entity',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to get entity: '.$e->getMessage(),
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end show()

    /**
     * Get entity types for filtering
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with entity types
     */
    public function getTypes(): JSONResponse
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('type')
                ->from('openregister_entities')
                ->orderBy('type', 'ASC');

            $result = $qb->executeQuery();
            $types  = [];

            while (($row = $result->fetch()) !== false) {
                $types[] = $row['type'];
            }

            $result->closeCursor();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => $types,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[GdprEntitiesController] Failed to get entity types',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to get entity types',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end getTypes()

    /**
     * Get entity categories for filtering
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with entity categories
     */
    public function getCategories(): JSONResponse
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('category')
                ->from('openregister_entities')
                ->orderBy('category', 'ASC');

            $result     = $qb->executeQuery();
            $categories = [];

            while (($row = $result->fetch()) !== false) {
                $categories[] = $row['category'];
            }

            $result->closeCursor();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => $categories,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[GdprEntitiesController] Failed to get entity categories',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to get entity categories',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end getCategories()

    /**
     * Get entity statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with entity statistics
     */
    public function getStats(): JSONResponse
    {
        try {
            // Total entities.
            $totalQb = $this->db->getQueryBuilder();
            $totalQb->select($totalQb->func()->count('*', 'total'))
                ->from('openregister_entities');
            $totalResult = $totalQb->executeQuery();
            $total       = (int) $totalResult->fetchOne();
            $totalResult->closeCursor();

            // Count by type.
            $typeQb = $this->db->getQueryBuilder();
            $typeQb->select('type')
                ->selectAlias($typeQb->func()->count('*'), 'count')
                ->from('openregister_entities')
                ->groupBy('type')
                ->orderBy('count', 'DESC');

            $typeResult = $typeQb->executeQuery();
            $byType     = [];

            while (($row = $typeResult->fetch()) !== false) {
                $byType[$row['type']] = (int) $row['count'];
            }

            $typeResult->closeCursor();

            // Count by category.
            $catQb = $this->db->getQueryBuilder();
            $catQb->select('category')
                ->selectAlias($catQb->func()->count('*'), 'count')
                ->from('openregister_entities')
                ->groupBy('category')
                ->orderBy('count', 'DESC');

            $catResult  = $catQb->executeQuery();
            $byCategory = [];

            while (($row = $catResult->fetch()) !== false) {
                $byCategory[$row['category']] = (int) $row['count'];
            }

            $catResult->closeCursor();

            // Total relations.
            $relQb = $this->db->getQueryBuilder();
            $relQb->select($relQb->func()->count('*', 'total'))
                ->from('openregister_entity_relations');
            $relResult      = $relQb->executeQuery();
            $totalRelations = (int) $relResult->fetchOne();
            $relResult->closeCursor();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'data'    => [
                        'totalEntities'  => $total,
                        'totalRelations' => $totalRelations,
                        'byType'         => $byType,
                        'byCategory'     => $byCategory,
                    ],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[GdprEntitiesController] Failed to get entity stats',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to get entity statistics',
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end getStats()

    /**
     * Delete an entity
     *
     * @param int $id Entity ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with deletion result
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $entity = $this->entityMapper->find($id);
            $this->entityMapper->delete($entity);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'Entity deleted successfully',
                ]
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Entity not found',
                ],
                statusCode: Http::STATUS_NOT_FOUND
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[GdprEntitiesController] Failed to delete entity',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to delete entity: '.$e->getMessage(),
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end destroy()
}//end class
