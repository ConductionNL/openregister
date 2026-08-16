<?php

/**
 * AVG Verwerkingsactiviteiten Controller.
 *
 * CRUD over the dedicated `oc_openregister_verwerkingsactiviteiten`
 * catalog plus the Art 30 §4 supervisory-review report endpoint
 * (`GET /api/avg/verantwoording`) that aggregates audit-trail rows
 * per processing activity.
 *
 * Authorization rules:
 *
 *   - List + show + verantwoording: any authenticated user. AVG Art 30 §4
 *     requires the verwerkingsregister to be available to supervisory
 *     authorities and indirectly to data subjects via inzage requests,
 *     so read paths intentionally don't gate on admin.
 *   - Create / update / delete: admin-only. Operators maintain the
 *     catalog; misconfigurations directly affect compliance.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST endpoints for managing AVG / GDPR Art 30 processing activities.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class VerwerkingsactiviteitenController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier.
	 * @param IRequest $request Active request.
	 * @param VerwerkingsactiviteitMapper $vrwMapper Mapper for the catalog.
	 * @param IUserSession $userSession Current user session.
	 * @param IGroupManager $groupManager Group manager (admin gate).
	 * @param IDBConnection $db DB for the verantwoording aggregation.
	 * @param OrganisationService $organisationService Org-scoping helper.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly VerwerkingsactiviteitMapper $vrwMapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IDBConnection $db,
		private readonly OrganisationService $organisationService,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * GET /api/avg/verwerkingsactiviteiten — list all activities.
	 *
	 * Optional query parameters: `status`, `organisation`.
	 *
	 * @return JSONResponse Wrapped list envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function index(): JSONResponse {
		// SECURITY (#1825): the processing register is tenant-scoped.
		// Non-admins only see activities bound to their own
		// organisation(s); admins see the full catalog.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$isAdmin = $this->isAdmin();
		$accessibleOrgs = [];
		if ($isAdmin === false) {
			$accessibleOrgs = $this->accessibleOrganisationUuids();
			if ($accessibleOrgs === []) {
				return new JSONResponse(data: ['count' => 0, 'results' => []]);
			}
		}

		$rows = $this->vrwMapper->findAll(
			organisationId: $this->optionalStringParam(key: 'organisation'),
			status: $this->optionalStringParam(key: 'status')
		);

		// Enforce tenant scoping in-controller: the mapper filter accepts
		// a single org while a user may belong to several, and a
		// caller-supplied `organisation` must never widen visibility.
		$visible = $this->filterVisibleActivities(activities: $rows, accessibleOrgs: $accessibleOrgs, isAdmin: $isAdmin);

		$results = [];
		foreach ($visible as $activity) {
			$results[] = $activity->jsonSerialize();
		}

		return new JSONResponse(
			data: [
				'count' => count($results),
				'results' => $results,
			]
		);

	}//end index()

	/**
	 * GET /api/avg/verwerkingsactiviteiten/{id} — fetch one.
	 *
	 * Accepts numeric id, uuid, or short readable code. Returns 404
	 * when nothing matches.
	 *
	 * @param string $id Identifier (id|uuid|code).
	 *
	 * @return JSONResponse The activity or a 404 envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$entity = $this->resolveOne(identifier: $id);
		$isAdmin = $this->isAdmin();

		$accessibleOrgs = [];
		if ($isAdmin === false) {
			$accessibleOrgs = $this->accessibleOrganisationUuids();
		}

		// SECURITY (#1825): hide cross-tenant activities from non-admins.
		// Return 404 rather than 403 so existence isn't leaked.
		$visible = false;
		if ($entity !== null) {
			$visible = $this->maySeeActivity(
				activity: $entity,
				accessibleOrgs: $accessibleOrgs,
				isAdmin: $isAdmin
			);
		}

		if ($visible === false) {
			return new JSONResponse(
				data: ['error' => 'Verwerkingsactiviteit not found', 'identifier' => $id],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: $entity->jsonSerialize());
	}//end show()

	/**
	 * POST /api/avg/verwerkingsactiviteiten — create one.
	 *
	 * Admin-only. Required fields: `naam`, `doelbinding`, `rechtsgrond`.
	 *
	 * @return JSONResponse The persisted activity (201) or a 422 envelope.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function create(): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		$payload = (array)($this->request->getParams() ?? []);

		try {
			$entity = $this->hydrateFromPayload(entity: new Verwerkingsactiviteit(), payload: $payload);
			$entity = $this->vrwMapper->insert($entity);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			data: $entity->jsonSerialize(),
			statusCode: Http::STATUS_CREATED
		);

	}//end create()

	/**
	 * PUT /api/avg/verwerkingsactiviteiten/{id} — update one.
	 *
	 * Admin-only.
	 *
	 * @param string $id Identifier (id|uuid|code).
	 *
	 * @return JSONResponse The updated activity, 404, 403, or 422.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function update(string $id): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		$entity = $this->resolveOne(identifier: $id);
		if ($entity === null) {
			return new JSONResponse(
				data: ['error' => 'Verwerkingsactiviteit not found', 'identifier' => $id],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$payload = (array)($this->request->getParams() ?? []);

		try {
			$entity = $this->hydrateFromPayload(entity: $entity, payload: $payload);
			$entity = $this->vrwMapper->update($entity);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(data: $entity->jsonSerialize());
	}//end update()

	/**
	 * DELETE /api/avg/verwerkingsactiviteiten/{id} — soft-archive.
	 *
	 * Admin-only. We never hard-delete: audit-trail rows reference
	 * activities by uuid as a soft FK and forensic legibility requires
	 * the catalog row to remain resolvable. Setting `status='archived'`
	 * keeps the row intact + flags it for the operator UI.
	 *
	 * @param string $id Identifier (id|uuid|code).
	 *
	 * @return JSONResponse 204 on success, 404, or 403.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function destroy(string $id): JSONResponse {
		if ($this->isAdmin() === false) {
			return $this->forbidden();
		}

		$entity = $this->resolveOne(identifier: $id);
		if ($entity === null) {
			return new JSONResponse(
				data: ['error' => 'Verwerkingsactiviteit not found', 'identifier' => $id],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		$entity->setStatus('archived');
		$this->vrwMapper->update($entity);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroy()

	/**
	 * GET /api/avg/verantwoording — Art 30 §4 supervisory-review report.
	 *
	 * Joins each verwerkingsactiviteit with the audit-trail row counts
	 * (per action) attributed to it. Suitable for AP supervisory
	 * review and the operator's annual `verantwoordingsdocument`.
	 *
	 * Response shape:
	 *
	 *   {
	 *     count: <int>,
	 *     activities: [
	 *       {
	 *         <full activity envelope>,
	 *         "activity": {
	 *           "totalEvents": <int>,
	 *           "byAction":    {"create": <int>, "update": <int>, "delete": <int>, "read": <int>}
	 *         }
	 *       },
	 *       ...
	 *     ]
	 *   }
	 *
	 * @return JSONResponse The verantwoordingsdocument envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function accountability(): JSONResponse {
		// SECURITY (#1825): the Art 30 §4 report exposes one tenant's full
		// processing register + audit aggregates. Non-admins only get
		// their own organisation(s); admins get the full report.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthorized();
		}

		$isAdmin = $this->isAdmin();
		$accessibleOrgs = [];
		if ($isAdmin === false) {
			$accessibleOrgs = $this->accessibleOrganisationUuids();
			if ($accessibleOrgs === []) {
				return new JSONResponse(data: ['count' => 0, 'activities' => []]);
			}
		}

		$activities = $this->filterVisibleActivities(
			activities: $this->vrwMapper->findAll(),
			accessibleOrgs: $accessibleOrgs,
			isAdmin: $isAdmin
		);

		$auditCounts = $this->aggregateAuditCounts(
			uuids: array_map(static fn (Verwerkingsactiviteit $a) => (string)$a->getUuid(), $activities)
		);

		$payload = [];
		foreach ($activities as $activity) {
			$row = $activity->jsonSerialize();
			$row['activity'] = $auditCounts[(string)$activity->getUuid()] ?? [
				'totalEvents' => 0,
				'byAction' => [],
			];
			$payload[] = $row;
		}

		return new JSONResponse(
			data: [
				'count' => count($payload),
				'activities' => $payload,
			]
		);

	}//end accountability()

	/**
	 * Hydrate (or update) a Verwerkingsactiviteit from a request payload.
	 *
	 * @param Verwerkingsactiviteit $entity Fresh or existing entity.
	 * @param array $payload Request body / query params.
	 *
	 * @return Verwerkingsactiviteit Hydrated entity (NOT yet persisted).
	 */
	private function hydrateFromPayload(Verwerkingsactiviteit $entity, array $payload): Verwerkingsactiviteit {
		$stringFields = [
			'code' => 'setCode',
			'naam' => 'setNaam',
			'beschrijving' => 'setBeschrijving',
			'doelbinding' => 'setDoelbinding',
			'rechtsgrond' => 'setRechtsgrond',
			'bewaartermijn' => 'setBewaartermijn',
			'technischeMaatregelen' => 'setTechnischeMaatregelen',
			'organisatorischeMaatregelen' => 'setOrganisatorischeMaatregelen',
			'organisationId' => 'setOrganisationId',
			'status' => 'setStatus',
		];
		foreach ($stringFields as $field => $setter) {
			if (array_key_exists($field, $payload) === true) {
				$value = null;
				if ($payload[$field] !== null) {
					$value = (string)$payload[$field];
				}

				$entity->{$setter}($value);
			}
		}

		$arrayFields = [
			'categorieenBetrokkenen' => 'setCategorieenBetrokkenen',
			'categorieenPersoonsgegevens' => 'setCategorieenPersoonsgegevens',
			'ontvangers' => 'setOntvangers',
			'doorgifteBuitenEu' => 'setDoorgifteBuitenEu',
			'verwerkingsverantwoordelijke' => 'setVerwerkingsverantwoordelijke',
			'contactgegevensFg' => 'setContactgegevensFg',
		];
		foreach ($arrayFields as $field => $setter) {
			if (array_key_exists($field, $payload) === true) {
				$value = $payload[$field];
				$hydrated = null;
				if (is_array($value) === true) {
					$hydrated = $value;
				}

				$entity->{$setter}($hydrated);
			}
		}

		return $entity;
	}//end hydrateFromPayload()

	/**
	 * Resolve a path identifier (id, uuid, or code) to an entity.
	 *
	 * @param string $identifier The path parameter value.
	 *
	 * @return Verwerkingsactiviteit|null Null when nothing matches.
	 */
	private function resolveOne(string $identifier): ?Verwerkingsactiviteit {
		if ($identifier === '') {
			return null;
		}

		if (ctype_digit($identifier) === true) {
			try {
				return $this->vrwMapper->find(id: (int)$identifier);
			} catch (DoesNotExistException $e) {
				return null;
			}
		}

		return $this->vrwMapper->resolveReference(reference: $identifier);
	}//end resolveOne()

	/**
	 * Aggregate `oc_openregister_audit_trails` rows by
	 * `processing_activity_id` + `action`, scoped to the given uuids.
	 *
	 * @param array<int, string> $uuids Activity uuids to aggregate against.
	 *
	 * @return array<string, array{totalEvents: int, byAction: array<string, int>}>
	 */
	private function aggregateAuditCounts(array $uuids): array {
		$uuids = array_values(array_filter($uuids, static fn ($v) => is_string($v) === true && $v !== ''));
		if ($uuids === []) {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('processing_activity_id', 'action')
				->selectAlias($qb->func()->count('*'), 'cnt')
				->from('openregister_audit_trails')
				->where(
					$qb->expr()->in(
						'processing_activity_id',
						$qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)
					)
				)
				->groupBy('processing_activity_id', 'action');

			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Exception $e) {
			return [];
		}

		$aggregate = [];
		foreach ($rows as $row) {
			$uuid = (string)($row['processing_activity_id'] ?? '');
			$action = (string)($row['action'] ?? '');
			$count = (int)($row['cnt'] ?? 0);
			$aggregate[$uuid] ??= ['totalEvents' => 0, 'byAction' => []];
			$aggregate[$uuid]['byAction'][$action] = $count;
			$aggregate[$uuid]['totalEvents'] += $count;
		}

		return $aggregate;
	}//end aggregateAuditCounts()

	/**
	 * Whether the active user is in the `admin` group.
	 *
	 * @return bool True when the active user is in the admin group.
	 */
	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return in_array(
			needle: 'admin',
			haystack: $this->groupManager->getUserGroupIds($user),
			strict: true
		);

	}//end isAdmin()

	/**
	 * Organisation UUIDs the active user is allowed to see.
	 *
	 * Returns the organisation UUIDs the current (non-admin) user
	 * belongs to. Admins are not scoped (callers should branch on
	 * `isAdmin()` first). An empty list means deny-all.
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
	 * Whether the active user may see an activity given its org binding.
	 *
	 * Admins see everything. Non-admins see activities bound to one of
	 * their organisations. Activities with no `organisationId` are
	 * treated as belonging to no tenant and are hidden from non-admins
	 * (fail-closed) — they remain visible to admins for maintenance.
	 *
	 * @param Verwerkingsactiviteit $activity Candidate activity.
	 * @param array<int, string> $accessibleOrgs Accessible org UUIDs.
	 * @param bool $isAdmin Whether caller is admin.
	 *
	 * @return bool True when the caller may see the activity.
	 */
	private function maySeeActivity(Verwerkingsactiviteit $activity, array $accessibleOrgs, bool $isAdmin): bool {
		if ($isAdmin === true) {
			return true;
		}

		$org = (string)$activity->getOrganisationId();
		if ($org === '') {
			return false;
		}

		return in_array($org, $accessibleOrgs, true);
	}//end maySeeActivity()

	/**
	 * Reduce a list of activities to the ones the caller may see.
	 *
	 * @param array<int, Verwerkingsactiviteit> $activities Candidates.
	 * @param array<int, string> $accessibleOrgs Accessible org UUIDs.
	 * @param bool $isAdmin Whether caller is admin.
	 *
	 * @return array<int, Verwerkingsactiviteit> Visible activities.
	 */
	private function filterVisibleActivities(array $activities, array $accessibleOrgs, bool $isAdmin): array {
		$visible = [];
		foreach ($activities as $activity) {
			$allowed = $this->maySeeActivity(
				activity: $activity,
				accessibleOrgs: $accessibleOrgs,
				isAdmin: $isAdmin
			);
			if ($allowed === true) {
				$visible[] = $activity;
			}
		}

		return $visible;
	}//end filterVisibleActivities()

	/**
	 * Read a request parameter as a non-empty string, or null.
	 *
	 * @param string $key Request parameter name.
	 *
	 * @return string|null Trimmed string value, or null when absent/empty.
	 */
	private function optionalStringParam(string $key): ?string {
		$value = $this->request->getParam(key: $key);
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end optionalStringParam()

	/**
	 * 403 envelope used by all admin-gated endpoints.
	 *
	 * @return JSONResponse Pre-baked 403 with explanatory message.
	 */
	private function forbidden(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Admin privileges required to manage verwerkingsactiviteiten'],
			statusCode: Http::STATUS_FORBIDDEN
		);

	}//end forbidden()

	/**
	 * 401 envelope used when no user is authenticated.
	 *
	 * @return JSONResponse Pre-baked 401.
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: ['error' => 'Authentication required'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);

	}//end unauthorized()
}//end class
