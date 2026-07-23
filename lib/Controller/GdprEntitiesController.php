<?php

/**
 * OpenRegister GDPR Entities Controller
 *
 * Controller for managing GDPR entities (detected PII) in the OpenRegister app.
 * Provides endpoints for listing, viewing, and managing detected entities
 * from text extraction and entity recognition.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use DateTime;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
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
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     * @param IUserSession         $userSession          Current user (admin gate)
     * @param IGroupManager        $groupManager         Group manager (admin gate)
     * @param OrganisationService  $organisationService  Org-scoping helper
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GdprEntityMapper $entityMapper,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly OrganisationService $organisationService
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
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function index(): JSONResponse
    {
        try {
            if ($this->userSession->getUser() === null) {
                return $this->unauthorized();
            }

            // SECURITY (#1825): rows in `openregister_entities` store raw
            // detected PII (names, emails, BSNs) and carry an
            // `organisation` UUID. Non-admins must only ever see PII
            // detected within their own organisation(s); admins see all.
            // A non-admin with no accessible organisation is denied
            // (empty result set, not an error) — fail-closed.
            $isAdmin  = $this->isAdmin();
            $orgUuids = [];
            if ($isAdmin === false) {
                $orgUuids = $this->accessibleOrganisationUuids();
                if ($orgUuids === []) {
                    return new JSONResponse(
                        data: [
                            'success' => true,
                            'data'    => [],
                            'count'   => 0,
                            'limit'   => (int) $this->request->getParam('limit', 50),
                            'offset'  => (int) $this->request->getParam('offset', 0),
                        ]
                    );
                }
            }

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

            // Tenant scoping for non-admins (#1825).
            $this->applyOrgFilter(qb: $qb, isAdmin: $isAdmin, orgUuids: $orgUuids, column: 'e.organisation');
            $this->applySearchFilters(qb: $qb, search: $search, type: $type, category: $category);

            // Get total count.
            $countQb = $this->db->getQueryBuilder();
            $countQb->select($countQb->func()->count('*', 'total'))
                ->from('openregister_entities', 'e');

            // Tenant scoping for non-admins (#1825).
            $this->applyOrgFilter(qb: $countQb, isAdmin: $isAdmin, orgUuids: $orgUuids, column: 'e.organisation');
            $this->applySearchFilters(qb: $countQb, search: $search, type: $type, category: $category);

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
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function show(int $id): JSONResponse
    {
        try {
            if ($this->userSession->getUser() === null) {
                return $this->unauthorized();
            }

            $entity = $this->entityMapper->find($id);

            // SECURITY (#1825): non-admins may only view PII detected
            // within an organisation they belong to. Return 404 (not
            // 403) so we don't leak the existence of cross-tenant rows.
            if ($this->isAdmin() === false) {
                $entityOrg = (string) $entity->getOrganisation();
                if ($entityOrg === '' || in_array($entityOrg, $this->accessibleOrganisationUuids(), true) === false) {
                    return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Entity not found',
                        ],
                        statusCode: Http::STATUS_NOT_FOUND
                    );
                }
            }

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
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function getTypes(): JSONResponse
    {
        try {
            if ($this->userSession->getUser() === null) {
                return $this->unauthorized();
            }

            $isAdmin  = $this->isAdmin();
            $orgUuids = [];
            if ($isAdmin === false) {
                $orgUuids = $this->accessibleOrganisationUuids();
                if ($orgUuids === []) {
                    return new JSONResponse(data: ['success' => true, 'data' => []]);
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('type')
                ->from('openregister_entities')
                ->orderBy('type', 'ASC');

            // Tenant scoping for non-admins (#1825).
            $this->applyOrgFilter(qb: $qb, isAdmin: $isAdmin, orgUuids: $orgUuids, column: 'organisation');

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
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function getCategories(): JSONResponse
    {
        try {
            if ($this->userSession->getUser() === null) {
                return $this->unauthorized();
            }

            $isAdmin  = $this->isAdmin();
            $orgUuids = [];
            if ($isAdmin === false) {
                $orgUuids = $this->accessibleOrganisationUuids();
                if ($orgUuids === []) {
                    return new JSONResponse(data: ['success' => true, 'data' => []]);
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->selectDistinct('category')
                ->from('openregister_entities')
                ->orderBy('category', 'ASC');

            // Tenant scoping for non-admins (#1825).
            $this->applyOrgFilter(qb: $qb, isAdmin: $isAdmin, orgUuids: $orgUuids, column: 'organisation');

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
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function getStats(): JSONResponse
    {
        try {
            if ($this->userSession->getUser() === null) {
                return $this->unauthorized();
            }

            // SECURITY (#1825): stats aggregate raw PII counts; non-admins
            // only see totals scoped to their own organisation(s).
            $isAdmin  = $this->isAdmin();
            $orgUuids = [];
            if ($isAdmin === false) {
                $orgUuids = $this->accessibleOrganisationUuids();
                if ($orgUuids === []) {
                    return new JSONResponse(
                        data: [
                            'success' => true,
                            'data'    => [
                                'totalEntities'  => 0,
                                'totalRelations' => 0,
                                'byType'         => [],
                                'byCategory'     => [],
                            ],
                        ]
                    );
                }
            }

            // Total entities.
            $totalQb = $this->db->getQueryBuilder();
            $totalQb->select($totalQb->func()->count('*', 'total'))
                ->from('openregister_entities');
            $this->applyOrgFilter(qb: $totalQb, isAdmin: $isAdmin, orgUuids: $orgUuids, column: 'organisation');

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
            $this->applyOrgFilter(qb: $typeQb, isAdmin: $isAdmin, orgUuids: $orgUuids, column: 'organisation');

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
            $this->applyOrgFilter(qb: $catQb, isAdmin: $isAdmin, orgUuids: $orgUuids, column: 'organisation');

            $catResult  = $catQb->executeQuery();
            $byCategory = [];

            while (($row = $catResult->fetch()) !== false) {
                $byCategory[$row['category']] = (int) $row['count'];
            }

            $catResult->closeCursor();

            // Total relations. For non-admins, only count relations whose
            // owning entity belongs to an accessible organisation (#1825).
            $relQb = $this->db->getQueryBuilder();
            $relQb->select($relQb->func()->count('*', 'total'))
                ->from('openregister_entity_relations', 'r');
            if ($isAdmin === false) {
                $relQb->innerJoin(
                    'r',
                    'openregister_entities',
                    'e',
                    $relQb->expr()->eq('r.entity_id', 'e.id')
                )
                    ->andWhere(
                        $relQb->expr()->in('e.organisation', $relQb->createNamedParameter($orgUuids, IQueryBuilder::PARAM_STR_ARRAY))
                    );
            }

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
     *
     * @spec openspec/specs/avg-verwerkingsregister/spec.md
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return $this->unauthorized();
            }

            $entity = $this->entityMapper->find($id);

            // SECURITY (#1825): non-admins may only delete PII detected
            // within an organisation they belong to. Return 404 (not
            // 403) so we don't leak existence of cross-tenant rows.
            if ($this->isAdmin() === false) {
                $entityOrg = (string) $entity->getOrganisation();
                if ($entityOrg === '' || in_array($entityOrg, $this->accessibleOrganisationUuids(), true) === false) {
                    return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Entity not found',
                        ],
                        statusCode: Http::STATUS_NOT_FOUND
                    );
                }
            }

            $this->entityMapper->delete($entity);

            // AVG / GDPR Art 30 §4: emit a structured audit record for the
            // PII-row deletion. GdprEntity rows are not ObjectEntity rows,
            // so the ObjectEntity-bound AuditTrailMapper does not apply;
            // we log to the application audit channel with the actor,
            // target identity and organisation for supervisory review.
            $this->logger->info(
                message: '[GdprEntitiesController] GDPR entity deleted',
                context: [
                    'audit'        => 'gdpr-entity-delete',
                    'entityId'     => $id,
                    'entityUuid'   => $entity->getUuid(),
                    'type'         => $entity->getType(),
                    'category'     => $entity->getCategory(),
                    'organisation' => $entity->getOrganisation(),
                    'deletedBy'    => $user->getUID(),
                    'deletedAt'    => (new DateTime())->format(DateTime::ATOM),
                ]
            );

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

    /**
     * Apply the non-admin organisation filter to a query builder.
     *
     * Centralises the `WHERE <column> IN (:orgUuids)` clause used to
     * tenant-scope the entity queries (#1825). For admins this is a
     * no-op (they see everything). Keeping the branch here keeps the
     * individual endpoint methods linear.
     *
     * @param IQueryBuilder      $qb       Query builder to constrain.
     * @param bool               $isAdmin  Whether the caller is an admin.
     * @param array<int, string> $orgUuids Accessible organisation UUIDs.
     * @param string             $column   Column holding the org UUID.
     *
     * @return void
     */
    private function applyOrgFilter(IQueryBuilder $qb, bool $isAdmin, array $orgUuids, string $column): void
    {
        if ($isAdmin === true) {
            return;
        }

        $qb->andWhere(
            $qb->expr()->in($column, $qb->createNamedParameter($orgUuids, IQueryBuilder::PARAM_STR_ARRAY))
        );
    }//end applyOrgFilter()

    /**
     * Apply the optional search / type / category filters to a query.
     *
     * Shared between the list query and its count query so both stay in
     * lock-step. Empty values are treated as "no filter".
     *
     * @param IQueryBuilder $qb       Query builder to constrain.
     * @param string        $search   Substring to match against `value`.
     * @param string        $type     Exact entity `type` filter.
     * @param string        $category Exact entity `category` filter.
     *
     * @return void
     */
    private function applySearchFilters(IQueryBuilder $qb, string $search, string $type, string $category): void
    {
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
    }//end applySearchFilters()

    /**
     * Whether the active user is in the `admin` group.
     *
     * @return bool True when the active user is an admin.
     */
    private function isAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        return $this->groupManager->isAdmin($user->getUID());
    }//end isAdmin()

    /**
     * Organisation UUIDs the active user is allowed to see.
     *
     * Returns the list of organisation UUIDs the current (non-admin)
     * user belongs to. Admins are not scoped (callers should branch on
     * `isAdmin()` first). An empty list means "no accessible
     * organisations" and callers MUST treat that as deny-all.
     *
     * @return array<int, string> Accessible organisation UUIDs.
     */
    private function accessibleOrganisationUuids(): array
    {
        $uuids = [];
        foreach ($this->organisationService->getUserOrganisations() as $organisation) {
            $uuid = (string) $organisation->getUuid();
            if ($uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        return array_values(array_unique($uuids));
    }//end accessibleOrganisationUuids()

    /**
     * 401 envelope used when no user is authenticated.
     *
     * @return JSONResponse Pre-baked 401.
     */
    private function unauthorized(): JSONResponse
    {
        return new JSONResponse(
            data: [
                'success' => false,
                'message' => 'Authentication required',
            ],
            statusCode: Http::STATUS_UNAUTHORIZED
        );
    }//end unauthorized()
}//end class
