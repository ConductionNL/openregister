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
use OCA\OpenRegister\Controller\Support\GdprEntitiesControllerServices;
use OCA\OpenRegister\Controller\Support\OrganisationScopeFilter;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Service\Decision\DecisionVisibilityInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
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
	 * GDPR entity mapper
	 *
	 * @var GdprEntityMapperInterface
	 */
	private readonly GdprEntityMapperInterface $entityMapper;

	/**
	 * Entity relation mapper
	 *
	 * @var EntityRelationMapperInterface
	 */
	private readonly EntityRelationMapperInterface $entityRelationMapper;

	/**
	 * Database connection
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Current user (admin gate)
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager (admin gate)
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Org-scoping helper
	 *
	 * @var OrganisationServiceInterface
	 */
	private readonly OrganisationServiceInterface $organisationService;

	/**
	 * Masks another reviewer's hidden decision
	 *
	 * @var DecisionVisibilityInterface
	 */
	private readonly DecisionVisibilityInterface $decisionVisibility;

	/**
	 * GdprEntitiesController constructor
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param GdprEntitiesControllerServices $services The gdpr entities controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		GdprEntitiesControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->entityMapper = $services->mappers->entityMapper;
		$this->entityRelationMapper = $services->mappers->entityRelationMapper;
		$this->db = $services->platform->db;
		$this->logger = $services->session->logger;
		$this->userSession = $services->session->userSession;
		$this->groupManager = $services->session->groupManager;
		$this->organisationService = $services->domain->organisationService;
		$this->decisionVisibility = $services->domain->decisionVisibility;
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

			return $this->listScopedEntities();
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[GdprEntitiesController] Failed to list entities',
				context: [...$this->errorContext(exception: $e), 'trace' => $e->getTraceAsString()]
			);

			return $this->serverError(message: 'Failed to list entities: ' . $e->getMessage());
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

			return $this->showVisibleEntity(id: $id, user: $user);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return $this->entityNotFound();
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[GdprEntitiesController] Failed to get entity',
				context: $this->entityErrorContext(id: $id, exception: $e)
			);

			return $this->serverError(message: 'Failed to get entity: ' . $e->getMessage());
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

			return $this->distinctValuesResponse(column: 'type');
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[GdprEntitiesController] Failed to get entity types',
				context: $this->errorContext(exception: $e)
			);

			return $this->serverError(message: 'Failed to get entity types');
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

			return $this->distinctValuesResponse(column: 'category');
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[GdprEntitiesController] Failed to get entity categories',
				context: $this->errorContext(exception: $e)
			);

			return $this->serverError(message: 'Failed to get entity categories');
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

			return $this->statsResponse();
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[GdprEntitiesController] Failed to get entity stats',
				context: $this->errorContext(exception: $e)
			);

			return $this->serverError(message: 'Failed to get entity statistics');
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

			return $this->destroyVisibleEntity(id: $id, user: $user);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return $this->entityNotFound();
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[GdprEntitiesController] Failed to delete entity',
				context: $this->entityErrorContext(id: $id, exception: $e)
			);

			return $this->serverError(message: 'Failed to delete entity: ' . $e->getMessage());
		}//end try
	}//end destroy()

	/**
	 * List the entities in the caller's organisation scope, one page at a time.
	 *
	 * SECURITY (#1825): rows in `openregister_entities` store raw detected PII
	 * (names, emails, BSNs) and carry an `organisation` UUID. Non-admins must
	 * only ever see PII detected within their own organisation(s); admins see
	 * all. A non-admin with no accessible organisation is denied (empty result
	 * set, not an error) — fail-closed.
	 *
	 * @return JSONResponse The page of entities
	 */
	private function listScopedEntities(): JSONResponse {
		$orgUuids = $this->organisationScope();
		if ($orgUuids === []) {
			return $this->emptyEntityPage();
		}

		return $this->entityPage(orgUuids: $orgUuids);
	}//end listScopedEntities()

	/**
	 * Count and fetch one page of entities within an organisation scope.
	 *
	 * @param array<int, string>|null $orgUuids Organisation scope (null = admin, unscoped)
	 *
	 * @return JSONResponse The page of entities with the total count
	 */
	private function entityPage(?array $orgUuids): JSONResponse {
		$filters = $this->listFilters();
		$qb = $this->entityListQuery(orgUuids: $orgUuids, filters: $filters);
		$total = $this->countListedEntities(orgUuids: $orgUuids, filters: $filters);
		$this->orderAndPage(qb: $qb, filters: $filters);

		return new JSONResponse(
			data: [
				'success' => true,
				'data' => $this->fetchEntityRows(qb: $qb),
				'count' => $total,
				'limit' => $filters['limit'],
				'offset' => $filters['offset'],
			]
		);
	}//end entityPage()

	/**
	 * The empty page a non-admin without any organisation gets.
	 *
	 * @return JSONResponse Empty page echoing the requested limit and offset
	 */
	private function emptyEntityPage(): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'data' => [],
				'count' => 0,
				'limit' => (int)$this->request->getParam('limit', 50),
				'offset' => (int)$this->request->getParam('offset', 0),
			]
		);
	}//end emptyEntityPage()

	/**
	 * Order the entity list newest detection first and limit it to the requested page.
	 *
	 * @param IQueryBuilder        $qb      The entity list query
	 * @param array<string, mixed> $filters The list filters (limit, offset)
	 *
	 * @return void
	 */
	private function orderAndPage(IQueryBuilder $qb, array $filters): void {
		$qb->orderBy('e.detected_at', 'DESC')
			->setMaxResults($filters['limit'])
			->setFirstResult($filters['offset']);
	}//end orderAndPage()

	/**
	 * Answer one entity with its relations, when the caller may see it.
	 *
	 * @param int   $id   The entity id
	 * @param IUser $user The caller
	 *
	 * @return JSONResponse The entity and its relations
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the entity is missing or outside the caller's organisations
	 */
	private function showVisibleEntity(int $id, IUser $user): JSONResponse {
		$entity = $this->findVisibleEntity(id: $id);

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
	}//end showVisibleEntity()

	/**
	 * Delete one entity, when the caller may see it, and write the audit record.
	 *
	 * @param int   $id   The entity id
	 * @param IUser $user The caller
	 *
	 * @return JSONResponse The deletion confirmation
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the entity is missing or outside the caller's organisations
	 */
	private function destroyVisibleEntity(int $id, IUser $user): JSONResponse {
		$entity = $this->findVisibleEntity(id: $id);

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
			] + $this->deletedEntityDetails(entity: $entity, user: $user)
		);

		return $this->entityDeleted();
	}//end destroyVisibleEntity()

	/**
	 * Find an entity the caller may see.
	 *
	 * SECURITY (#1825): non-admins may only see or delete PII detected within
	 * an organisation they belong to. A row outside them is reported exactly as
	 * a missing row (404, not 403), so the existence of cross-tenant rows does
	 * not leak.
	 *
	 * @param int $id The entity id
	 *
	 * @return GdprEntity The entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the entity is missing or outside the caller's organisations
	 */
	private function findVisibleEntity(int $id): GdprEntity {
		$entity = $this->entityMapper->find($id);

		if ($this->isOutsideCallerOrganisations(entity: $entity) === true) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('Entity not found');
		}

		return $entity;
	}//end findVisibleEntity()

	/**
	 * Whether a non-admin caller is outside the entity's organisation.
	 *
	 * An admin is never outside; a row without organisation is outside for every non-admin.
	 *
	 * @param GdprEntity $entity The entity
	 *
	 * @return bool True when the caller may not see the entity
	 */
	private function isOutsideCallerOrganisations(GdprEntity $entity): bool {
		if ($this->isAdmin() === true) {
			return false;
		}

		$entityOrg = (string)$entity->getOrganisation();

		return $entityOrg === '' || in_array($entityOrg, $this->accessibleOrganisationUuids(), true) === false;
	}//end isOutsideCallerOrganisations()

	/**
	 * The audit details of a deleted entity: what it was and who deleted it when.
	 *
	 * @param GdprEntity $entity The deleted entity
	 * @param IUser      $user   The caller who deleted it
	 *
	 * @return array<string, mixed> Audit context fields
	 */
	private function deletedEntityDetails(GdprEntity $entity, IUser $user): array {
		return [
			'entityUuid' => $entity->getUuid(),
			'type' => $entity->getType(),
			'category' => $entity->getCategory(),
			'organisation' => $entity->getOrganisation(),
			'deletedBy' => $user->getUID(),
			'deletedAt' => (new DateTime())->format(DateTime::ATOM),
		];
	}//end deletedEntityDetails()

	/**
	 * The answer to a successful deletion.
	 *
	 * @return JSONResponse Deletion confirmation
	 */
	private function entityDeleted(): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'Entity deleted successfully',
			]
		);
	}//end entityDeleted()

	/**
	 * The distinct values of one entity column within the caller's organisation scope.
	 *
	 * @param string $column The column: `type` or `category`
	 *
	 * @return JSONResponse The sorted distinct values
	 */
	private function distinctValuesResponse(string $column): JSONResponse {
		$orgUuids = $this->organisationScope();
		if ($orgUuids === []) {
			return new JSONResponse(data: ['success' => true, 'data' => []]);
		}

		return new JSONResponse(
			data: [
				'success' => true,
				'data' => $this->distinctValues(column: $column, orgUuids: $orgUuids),
			]
		);
	}//end distinctValuesResponse()

	/**
	 * Query the sorted distinct values of one entity column.
	 *
	 * @param string                  $column   The column
	 * @param array<int, string>|null $orgUuids Organisation scope (null = admin, unscoped)
	 *
	 * @return array<int, mixed> The values
	 */
	private function distinctValues(string $column, ?array $orgUuids): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct($column)
			->from('openregister_entities')
			->orderBy($column, 'ASC');

		// Tenant scoping for non-admins (#1825).
		(new OrganisationScopeFilter(uuids: $orgUuids))->narrow(qb: $qb, column: 'organisation');

		$result = $qb->executeQuery();
		$values = [];

		while (($row = $result->fetch()) !== false) {
			$values[] = $row[$column];
		}

		$result->closeCursor();

		return $values;
	}//end distinctValues()

	/**
	 * Answer the entity statistics within the caller's organisation scope.
	 *
	 * SECURITY (#1825): stats aggregate raw PII counts; non-admins only see
	 * totals scoped to their own organisation(s).
	 *
	 * @return JSONResponse The statistics
	 */
	private function statsResponse(): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'data' => $this->scopedStats(orgUuids: $this->organisationScope()),
			]
		);
	}//end statsResponse()

	/**
	 * Entity and relation totals within the caller's organisation scope.
	 *
	 * A non-admin without organisation gets all-zero totals without any query.
	 *
	 * @param array<int, string>|null $orgUuids Organisation scope (null = admin, unscoped)
	 *
	 * @return array<string, mixed> The statistics
	 */
	private function scopedStats(?array $orgUuids): array {
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

		return $stats;
	}//end scopedStats()

	/**
	 * Log context for a failed action: where, and the error message.
	 *
	 * @param \Exception $exception The failure
	 *
	 * @return array<string, mixed> Log context
	 */
	private function errorContext(\Exception $exception): array {
		return ['file' => __FILE__, 'line' => __LINE__, 'error' => $exception->getMessage()];
	}//end errorContext()

	/**
	 * Log context for a failed action on one entity: where, which entity, and the error message.
	 *
	 * @param int        $id        The entity id
	 * @param \Exception $exception The failure
	 *
	 * @return array<string, mixed> Log context
	 */
	private function entityErrorContext(int $id, \Exception $exception): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'id' => $id,
			'error' => $exception->getMessage(),
		];
	}//end entityErrorContext()

	/**
	 * The 404 answer for a missing entity, or one outside the caller's organisations.
	 *
	 * @return JSONResponse 404 response
	 */
	private function entityNotFound(): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => false,
				'message' => 'Entity not found',
			],
			statusCode: Http::STATUS_NOT_FOUND
		);
	}//end entityNotFound()

	/**
	 * The 500 answer for a failed action.
	 *
	 * @param string $message The message to report
	 *
	 * @return JSONResponse 500 response
	 */
	private function serverError(string $message): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => false,
				'message' => $message,
			],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end serverError()

	/**
	 * Organisation UUIDs the active user's entity reads are scoped to.
	 *
	 * @return array<int, string>|null Null for an admin (unscoped); otherwise
	 *                                 the accessible UUIDs, where an empty list
	 *                                 means deny-all.
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

		(new OrganisationScopeFilter(uuids: $orgUuids))->narrow(qb: $qb, column: 'e.organisation');
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

		(new OrganisationScopeFilter(uuids: $orgUuids))->narrow(qb: $countQb, column: 'e.organisation');
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
		(new OrganisationScopeFilter(uuids: $orgUuids))->narrow(qb: $totalQb, column: 'organisation');

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
		(new OrganisationScopeFilter(uuids: $orgUuids))->narrow(qb: $qb, column: 'organisation');

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
	 * Returns the list of organisation UUIDs the current (non-admin)
	 * user belongs to. Admins are not scoped (callers should branch on
	 * `isAdmin()` first). An empty list means "no accessible
	 * organisations" and callers MUST treat that as deny-all.
	 *
	 * @return array<int, string> Accessible organisation UUIDs.
	 */
	private function accessibleOrganisationUuids(): array {
		$uuids = [];
		foreach ($this->organisationService->getUserOrganisations() as $organisation) {
			$uuid = (string)$organisation->getUuid();
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
