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
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Db\GdprEntityMapper;
use OCA\OpenRegister\Service\Decision\DecisionVisibility;
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
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI: the tenth is the REQ-ROD-002 decision filter.
 */
class GdprEntitiesController extends Controller {
	/**
	 * GdprEntitiesController constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param GdprEntityMapper $entityMapper GDPR entity mapper
	 * @param EntityRelationMapper $entityRelationMapper Entity relation mapper
	 * @param IDBConnection $db Database connection
	 * @param LoggerInterface $logger Logger
	 * @param IUserSession $userSession Current user (admin gate)
	 * @param IGroupManager $groupManager Group manager (admin gate)
	 * @param OrganisationService $organisationService Org-scoping helper
	 * @param DecisionVisibility $decisionVisibility Masks another reviewer's hidden decision
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
		private readonly OrganisationService $organisationService,
		private readonly DecisionVisibility $decisionVisibility,
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
	public function index(): JSONResponse {
		try {
			if ($this->userSession->getUser() === null) {
				return $this->unauthorized();
			}

			// SECURITY (#1825): rows in `openregister_entities` store raw
			// detected PII (names, emails, BSNs) and carry an
			// `organisation` UUID. Non-admins only see PII detected within
			// their active organisation; admins see all. A non-admin
			// without an active organisation is denied (empty result set,
			// not an error): fail-closed.
			$orgUuids = $this->organisationScope();
			if ($orgUuids === []) {
				return new JSONResponse(
					data: [
						'success' => true,
						'data' => [],
						'count' => 0,
						'limit' => (int)$this->request->getParam('limit', 50),
						'offset' => (int)$this->request->getParam('offset', 0),
					]
				);
			}

			$filters = $this->listFilters();
			$qb = $this->entityListQuery(orgUuids: $orgUuids, filters: $filters);
			$total = $this->countListedEntities(orgUuids: $orgUuids, filters: $filters);

			$qb->orderBy('e.detected_at', 'DESC')
				->setMaxResults($filters['limit'])
				->setFirstResult($filters['offset']);

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $this->fetchEntityRows(qb: $qb),
					'count' => $total,
					'limit' => $filters['limit'],
					'offset' => $filters['offset'],
				]
			);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[GdprEntitiesController] Failed to list entities',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'message' => 'Failed to list entities: ' . $e->getMessage(),
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
	public function show(int $id): JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return $this->unauthorized();
			}

			$entity = $this->entityMapper->find($id);

			// SECURITY (#1825): non-admins may only view PII detected
			// within their active organisation. Return 404 (not
			// 403) so we don't leak the existence of cross-tenant rows.
			if ($this->isAdmin() === false) {
				$entityOrg = (string)$entity->getOrganisation();
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
					'success' => true,
					'data' => $entity->jsonSerialize(),
					// REQ-ROD-002: another reviewer's decision is hidden under hideOthers.
					'relations' => $this->decisionVisibility->presentAll(
						relations: $relations,
						userId: $user->getUID()
					),
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
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'error' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'message' => 'Failed to get entity: ' . $e->getMessage(),
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
	public function getTypes(): JSONResponse {
		try {
			if ($this->userSession->getUser() === null) {
				return $this->unauthorized();
			}

			$orgUuids = $this->organisationScope();
			if ($orgUuids === []) {
				return new JSONResponse(data: ['success' => true, 'data' => []]);
			}

			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('type')
				->from('openregister_entities')
				->orderBy('type', 'ASC');

			// Tenant scoping for non-admins (#1825).
			$this->applyOrgFilter(qb: $qb, orgUuids: $orgUuids, column: 'organisation');

			$result = $qb->executeQuery();
			$types = [];

			while (($row = $result->fetch()) !== false) {
				$types[] = $row['type'];
			}

			$result->closeCursor();

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $types,
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
	public function getCategories(): JSONResponse {
		try {
			if ($this->userSession->getUser() === null) {
				return $this->unauthorized();
			}

			$orgUuids = $this->organisationScope();
			if ($orgUuids === []) {
				return new JSONResponse(data: ['success' => true, 'data' => []]);
			}

			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('category')
				->from('openregister_entities')
				->orderBy('category', 'ASC');

			// Tenant scoping for non-admins (#1825).
			$this->applyOrgFilter(qb: $qb, orgUuids: $orgUuids, column: 'organisation');

			$result = $qb->executeQuery();
			$categories = [];

			while (($row = $result->fetch()) !== false) {
				$categories[] = $row['category'];
			}

			$result->closeCursor();

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $categories,
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
	public function getStats(): JSONResponse {
		try {
			if ($this->userSession->getUser() === null) {
				return $this->unauthorized();
			}

			// SECURITY (#1825): stats aggregate raw PII counts; non-admins
			// only see totals scoped to their active organisation.
			$orgUuids = $this->organisationScope();
			$stats = [
				'totalEntities' => 0,
				'totalRelations' => 0,
				'byType' => [],
				'byCategory' => [],
			];
			if ($orgUuids !== []) {
				$stats['totalEntities'] = $this->countAllEntities(orgUuids: $orgUuids);
				$stats['byType'] = $this->countEntitiesBy(column: 'type', orgUuids: $orgUuids);
				$stats['byCategory'] = $this->countEntitiesBy(column: 'category', orgUuids: $orgUuids);
				$stats['totalRelations'] = $this->countRelations(orgUuids: $orgUuids);
			}

			return new JSONResponse(
				data: [
					'success' => true,
					'data' => $stats,
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
	public function destroy(int $id): JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return $this->unauthorized();
			}

			$entity = $this->entityMapper->find($id);

			// SECURITY (#1825): non-admins may only delete PII detected
			// within their active organisation. Return 404 (not
			// 403) so we don't leak existence of cross-tenant rows.
			if ($this->isAdmin() === false) {
				$entityOrg = (string)$entity->getOrganisation();
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
					'audit' => 'gdpr-entity-delete',
					'entityId' => $id,
					'entityUuid' => $entity->getUuid(),
					'type' => $entity->getType(),
					'category' => $entity->getCategory(),
					'organisation' => $entity->getOrganisation(),
					'deletedBy' => $user->getUID(),
					'deletedAt' => (new DateTime())->format(DateTime::ATOM),
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
					'file' => __FILE__,
					'line' => __LINE__,
					'id' => $id,
					'error' => $e->getMessage(),
				]
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'message' => 'Failed to delete entity: ' . $e->getMessage(),
				],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end destroy()

	/**
	 * Organisation UUIDs the active user's entity reads are scoped to.
	 *
	 * @return array<int, string>|null Null for an admin (unscoped); otherwise
	 *                                 the active organisation's UUID, where an
	 *                                 empty list means deny-all.
	 */
	private function organisationScope(): ?array {
		if ($this->isAdmin() === true) {
			return null;
		}

		return $this->accessibleOrganisationUuids();
	}//end organisationScope()

	/**
	 * Read the list endpoint's pagination and filter parameters.
	 *
	 * @return array{limit: int, offset: int, search: string, type: string, category: string}
	 */
	private function listFilters(): array {
		return [
			'limit' => (int)$this->request->getParam('limit', 50),
			'offset' => (int)$this->request->getParam('offset', 0),
			'search' => $this->textParam(key: 'search'),
			'type' => $this->textParam(key: 'type'),
			'category' => $this->textParam(key: 'category'),
		];
	}//end listFilters()

	/**
	 * Read a string request parameter, defaulting to an empty string.
	 *
	 * @param string $key Parameter name.
	 *
	 * @return string The parameter value.
	 */
	private function textParam(string $key): string {
		return $this->request->getParam($key, '');
	}//end textParam()

	/**
	 * Build the scoped and filtered entity list query with its relation count.
	 *
	 * @param array<int, string>|null $orgUuids Organisation scope, null for admins.
	 * @param array<string, mixed>    $filters  The list filters.
	 *
	 * @return IQueryBuilder The unpaginated list query.
	 */
	private function entityListQuery(?array $orgUuids, array $filters): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();

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
			->selectAlias($qb->createFunction('(' . $subQb->getSQL() . ')'), 'relation_count')
			->from('openregister_entities', 'e');

		$this->applyOrgFilter(qb: $qb, orgUuids: $orgUuids, column: 'e.organisation');
		$this->applySearchFilters(qb: $qb, filters: $filters);

		return $qb;
	}//end entityListQuery()

	/**
	 * Count the entities matching the list scope and filters.
	 *
	 * @param array<int, string>|null $orgUuids Organisation scope, null for admins.
	 * @param array<string, mixed>    $filters  The list filters.
	 *
	 * @return int The total number of matching entities.
	 */
	private function countListedEntities(?array $orgUuids, array $filters): int {
		$countQb = $this->db->getQueryBuilder();
		$countQb->select($countQb->func()->count('*', 'total'))
			->from('openregister_entities', 'e');

		$this->applyOrgFilter(qb: $countQb, orgUuids: $orgUuids, column: 'e.organisation');
		$this->applySearchFilters(qb: $countQb, filters: $filters);

		return $this->fetchCount(qb: $countQb);
	}//end countListedEntities()

	/**
	 * Run the list query and map its rows to the response shape.
	 *
	 * @param IQueryBuilder $qb The paginated list query.
	 *
	 * @return array<int, array<string, mixed>> The entity rows.
	 */
	private function fetchEntityRows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$entities = [];

		while (($row = $result->fetch()) !== false) {
			$entities[] = [
				'id' => (int)$row['id'],
				'uuid' => $row['uuid'],
				'type' => $row['type'],
				'value' => $row['value'],
				'category' => $row['category'],
				'detectedAt' => $row['detected_at'],
				'updatedAt' => $row['updated_at'],
				'relationCount' => (int)$row['relation_count'],
			];
		}

		$result->closeCursor();

		return $entities;
	}//end fetchEntityRows()

	/**
	 * Count all entities in the organisation scope.
	 *
	 * @param array<int, string>|null $orgUuids Organisation scope, null for admins.
	 *
	 * @return int The number of entities.
	 */
	private function countAllEntities(?array $orgUuids): int {
		$totalQb = $this->db->getQueryBuilder();
		$totalQb->select($totalQb->func()->count('*', 'total'))
			->from('openregister_entities');
		$this->applyOrgFilter(qb: $totalQb, orgUuids: $orgUuids, column: 'organisation');

		return $this->fetchCount(qb: $totalQb);
	}//end countAllEntities()

	/**
	 * Count the scoped entities per value of a column, largest first.
	 *
	 * @param string                  $column   The column to group by (`type` or `category`).
	 * @param array<int, string>|null $orgUuids Organisation scope, null for admins.
	 *
	 * @return array<string, int> Counts keyed by column value.
	 */
	private function countEntitiesBy(string $column, ?array $orgUuids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)
			->selectAlias($qb->func()->count('*'), 'count')
			->from('openregister_entities')
			->groupBy($column)
			->orderBy('count', 'DESC');
		$this->applyOrgFilter(qb: $qb, orgUuids: $orgUuids, column: 'organisation');

		$result = $qb->executeQuery();
		$counts = [];

		while (($row = $result->fetch()) !== false) {
			$counts[$row[$column]] = (int)$row['count'];
		}

		$result->closeCursor();

		return $counts;
	}//end countEntitiesBy()

	/**
	 * Count the entity relations whose entity is in the organisation scope.
	 *
	 * @param array<int, string>|null $orgUuids Organisation scope, null for admins.
	 *
	 * @return int The number of relations.
	 */
	private function countRelations(?array $orgUuids): int {
		$relQb = $this->db->getQueryBuilder();
		$relQb->select($relQb->func()->count('*', 'total'))
			->from('openregister_entity_relations', 'r');
		if ($orgUuids !== null) {
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

		return $this->fetchCount(qb: $relQb);
	}//end countRelations()

	/**
	 * Run a single-value count query.
	 *
	 * @param IQueryBuilder $qb The count query.
	 *
	 * @return int The count.
	 */
	private function fetchCount(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end fetchCount()

	/**
	 * Apply the non-admin organisation filter to a query builder.
	 *
	 * Centralises the `WHERE <column> IN (:orgUuids)` clause used to
	 * tenant-scope the entity queries (#1825). For admins (a null scope)
	 * this is a no-op.
	 *
	 * @param IQueryBuilder           $qb       Query builder to constrain.
	 * @param array<int, string>|null $orgUuids Accessible organisation UUIDs, null for admins.
	 * @param string                  $column   Column holding the org UUID.
	 *
	 * @return void
	 */
	private function applyOrgFilter(IQueryBuilder $qb, ?array $orgUuids, string $column): void {
		if ($orgUuids === null) {
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
	 * @param IQueryBuilder        $qb      Query builder to constrain.
	 * @param array<string, mixed> $filters The list filters (`search` matches `value` as a substring).
	 *
	 * @return void
	 */
	private function applySearchFilters(IQueryBuilder $qb, array $filters): void {
		if ($filters['search'] !== '') {
			$qb->andWhere(
				$qb->expr()->iLike('e.value', $qb->createNamedParameter('%' . $filters['search'] . '%'))
			);
		}

		if ($filters['type'] !== '') {
			$qb->andWhere(
				$qb->expr()->eq('e.type', $qb->createNamedParameter($filters['type']))
			);
		}

		if ($filters['category'] !== '') {
			$qb->andWhere(
				$qb->expr()->eq('e.category', $qb->createNamedParameter($filters['category']))
			);
		}
	}//end applySearchFilters()

	/**
	 * Whether the active user is in the `admin` group.
	 *
	 * @return bool True when the active user is an admin.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isAdmin()

	/**
	 * Organisation UUIDs the active user is allowed to see.
	 *
	 * Returns the UUID of the current (non-admin) user's active
	 * organisation, not every organisation they belong to. Admins are not
	 * scoped (callers should branch on `isAdmin()` first). An empty list
	 * means "no active organisation" and callers MUST treat that as
	 * deny-all.
	 *
	 * @return array<int, string> Accessible organisation UUIDs.
	 */
	private function accessibleOrganisationUuids(): array {
		$uuid = (string)$this->organisationService->getActiveOrganisation()?->getUuid();
		if ($uuid === '') {
			return [];
		}

		return [$uuid];
	}//end accessibleOrganisationUuids()

	/**
	 * 401 envelope used when no user is authenticated.
	 *
	 * @return JSONResponse Pre-baked 401.
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => false,
				'message' => 'Authentication required',
			],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthorized()
}//end class
