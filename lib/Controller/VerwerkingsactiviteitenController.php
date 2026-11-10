<?php

/**
 * AVG Verwerkingsactiviteiten Controller.
 *
 * CRUD over the dedicated `oc_openregister_verwerkingsactiviteiten`
 * catalog plus the Art 30 §4 supervisory-review report endpoint
 * (`GET /api/avg/accountability`) that aggregates audit-trail rows
 * per processing activity.
 *
 * Authorization rules:
 *
 *   - List, show and accountability: any authenticated user, within their
 *     active organisation; administrators see every activity. AVG Art 30 §4
 *     requires the verwerkingsregister to be available to supervisory
 *     authorities and indirectly to data subjects via access requests, so
 *     read paths intentionally don't gate on admin.
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

use Closure;
use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\GdprRegisterAccess;
use OCA\OpenRegister\Controller\Support\GdprRegisterReplies;
use OCA\OpenRegister\Controller\Support\GdprRequestParams;
use OCA\OpenRegister\Controller\Support\ProcessingActivityAuditCounts;
use OCA\OpenRegister\Controller\Support\ProcessingActivityHydrator;
use OCA\OpenRegister\Controller\Support\ProcessingActivityVisibility;
use OCA\OpenRegister\Controller\Support\VerwerkingsactiviteitenControllerServices;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;

/**
 * REST endpoints for managing AVG / GDPR Art 30 processing activities.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class VerwerkingsactiviteitenController extends Controller {
	/**
	 * The caller and the admin check.
	 *
	 * @var GdprRegisterAccess
	 */
	private readonly GdprRegisterAccess $access;

	/**
	 * The refusal envelopes.
	 *
	 * @var GdprRegisterReplies
	 */
	private readonly GdprRegisterReplies $replies;

	/**
	 * The optional query parameters and the payload.
	 *
	 * @var GdprRequestParams
	 */
	private readonly GdprRequestParams $params;

	/**
	 * Tenant scoping of the catalog.
	 *
	 * @var ProcessingActivityVisibility
	 */
	private readonly ProcessingActivityVisibility $visibility;

	/**
	 * The audit-trail counts per activity.
	 *
	 * @var ProcessingActivityAuditCounts
	 */
	private readonly ProcessingActivityAuditCounts $auditCounts;

	/**
	 * The catalog mapper, session, database and org scoping.
	 *
	 * @var VerwerkingsactiviteitenControllerServices
	 */
	private readonly VerwerkingsactiviteitenControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest                                  $request  Active request.
	 * @param VerwerkingsactiviteitenControllerServices $services The catalog mapper, session, database and org scoping.
	 */
	public function __construct(
		IRequest $request,
		VerwerkingsactiviteitenControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
		$this->visibility = new ProcessingActivityVisibility(organisationService: $services->organisationService);
		$this->auditCounts = new ProcessingActivityAuditCounts(db: $services->db);
		$this->access = new GdprRegisterAccess(session: $services->session);
		$this->params = new GdprRequestParams(request: $request);
		$this->replies = new GdprRegisterReplies();
	}//end __construct()

	/**
	 * GET /api/avg/processing-activities — list all activities.
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
		// The processing register is tenant-scoped: a non-admin sees the
		// activities of their active organisation, an admin the full catalog.
		if ($this->access->user() === null) {
			return $this->replies->unauthorized();
		}

		$scope = $this->callerScope();
		if ($scope === null) {
			return new JSONResponse(data: ['count' => 0, 'results' => []]);
		}

		$rows = $this->services->vrwMapper->findAll(
			organisationId: $this->params->nonEmpty(key: 'organisation'),
			status: $this->params->nonEmpty(key: 'status')
		);

		// Enforce tenant scoping in-controller: the mapper filter accepts
		// a single org while a user may belong to several, and a
		// caller-supplied `organisation` must never widen visibility.
		$visible = $this->visibility->filter(activities: $rows, accessibleOrgs: $scope['orgs'], isAdmin: $scope['admin']);

		$results = array_map(static fn (Verwerkingsactiviteit $activity): array => $activity->jsonSerialize(), $visible);

		return new JSONResponse(data: ['count' => count($visible), 'results' => $results]);
	}//end index()

	/**
	 * GET /api/avg/processing-activities/{id} — fetch one.
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
		if ($this->access->user() === null) {
			return $this->replies->unauthorized();
		}

		$entity = $this->resolveOne(identifier: $id);
		$isAdmin = $this->access->isAdmin();
		$accessibleOrgs = $this->visibility->accessibleOrgs(isAdmin: $isAdmin);

		// SECURITY (#1825): hide cross-tenant activities from non-admins.
		// Return 404 rather than 403 so existence isn't leaked.
		if ($entity === null
			|| $this->visibility->maySee(activity: $entity, accessibleOrgs: $accessibleOrgs, isAdmin: $isAdmin) === false
		) {
			return $this->notFound(identifier: $id);
		}

		return new JSONResponse(data: $entity->jsonSerialize());
	}//end show()

	/**
	 * POST /api/avg/processing-activities — create one.
	 *
	 * Admin-only. Required fields: `name`, `purpose`, `legalBasis`.
	 *
	 * @return JSONResponse The persisted activity (201) or a 422 envelope.
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function create(): JSONResponse {
		if ($this->access->isAdmin() === false) {
			return $this->forbidden();
		}

		$payload = $this->params->payload();

		return $this->persist(
			write: fn () => $this->services->vrwMapper->insert($this->hydrated(entity: new Verwerkingsactiviteit(), payload: $payload)),
			status: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * PUT /api/avg/processing-activities/{id} — update one.
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
		$entity = $this->administered(identifier: $id);
		if ($entity instanceof JSONResponse) {
			return $entity;
		}

		$payload = $this->params->payload();

		return $this->persist(
			write: fn () => $this->services->vrwMapper->update($this->hydrated(entity: $entity, payload: $payload)),
			status: Http::STATUS_OK
		);
	}//end update()

	/**
	 * DELETE /api/avg/processing-activities/{id} — soft-archive.
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
		$entity = $this->administered(identifier: $id);
		if ($entity instanceof JSONResponse) {
			return $entity;
		}

		$entity->setStatus('archived');
		$this->services->vrwMapper->update($entity);

		return new JSONResponse(data: [], statusCode: Http::STATUS_NO_CONTENT);
	}//end destroy()

	/**
	 * GET /api/avg/accountability — Art 30 §4 supervisory-review report.
	 *
	 * Joins each verwerkingsactiviteit with the audit-trail row counts
	 * (per action) attributed to it. Suitable for AP supervisory
	 * review and the operator's annual accountability document
	 * (Dutch: verantwoordingsdocument).
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
	 * @return JSONResponse The accountability document envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md#requirement-the-system-must-provide-an-art-30-4-accountability-report-aggregating-audit-events-per-processing-activity
	 */
	public function accountability(): JSONResponse {
		// The Art 30 §4 report exposes one organisation's processing register
		// and the audit counts of its activities. Audit rows carry no
		// reliable organisation, so the report is scoped through the
		// activities: a non-admin gets those of their active organisation,
		// and rows are counted for those activities only.
		if ($this->access->user() === null) {
			return $this->replies->unauthorized();
		}

		$scope = $this->callerScope();
		if ($scope === null) {
			return new JSONResponse(data: ['count' => 0, 'activities' => []]);
		}

		$all = $this->services->vrwMapper->findAll();
		$activities = $this->visibility->filter(activities: $all, accessibleOrgs: $scope['orgs'], isAdmin: $scope['admin']);

		$payload = $this->withAuditCounts(activities: $activities);

		return new JSONResponse(data: ['count' => count($payload), 'activities' => $payload]);
	}//end accountability()

	/**
	 * The caller's tenant scope, or null when a non-admin has no organisation.
	 *
	 * A null scope is deny-all: the caller is given an empty list and the
	 * catalog is not read.
	 *
	 * @return array{admin: bool, orgs: array<int, string>}|null The scope.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function callerScope(): ?array {
		$isAdmin = $this->access->isAdmin();
		$accessibleOrgs = $this->visibility->accessibleOrgs(isAdmin: $isAdmin);
		if ($isAdmin === false && $accessibleOrgs === []) {
			return null;
		}

		return ['admin' => $isAdmin, 'orgs' => $accessibleOrgs];
	}//end callerScope()

	/**
	 * The activities as published, each with its audit-trail counts.
	 *
	 * @param array<int, Verwerkingsactiviteit> $activities The visible activities.
	 *
	 * @return array<int, array<string, mixed>> The report rows.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function withAuditCounts(array $activities): array {
		$auditCounts = $this->auditCounts->byActivity(
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

		return $payload;
	}//end withAuditCounts()

	/**
	 * The activity an administrator asked to change, or the refusal.
	 *
	 * The admin check runs before the activity is looked up.
	 *
	 * @param string $identifier Identifier (id|uuid|code).
	 *
	 * @return Verwerkingsactiviteit|JSONResponse The activity, or HTTP 403 / 404.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function administered(string $identifier): Verwerkingsactiviteit|JSONResponse {
		if ($this->access->isAdmin() === false) {
			return $this->forbidden();
		}

		return $this->resolveOne(identifier: $identifier) ?? $this->notFound(identifier: $identifier);
	}//end administered()

	/**
	 * Hydrate and write an activity, or answer 422 on an invalid argument.
	 *
	 * @param Closure $write  Hydrates, inserts or updates, and returns the activity.
	 * @param int     $status The status of the success response.
	 *
	 * @return JSONResponse The persisted activity, or HTTP 422.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	private function persist(Closure $write, int $status): JSONResponse {
		$entity = $this->replies->attempt(write: $write, catch: InvalidArgumentException::class);
		if ($entity instanceof JSONResponse) {
			return $entity;
		}

		return new JSONResponse(data: $entity->jsonSerialize(), statusCode: $status);
	}//end persist()

	/**
	 * Hydrate (or update) an activity from a request payload.
	 *
	 * @param Verwerkingsactiviteit $entity  Fresh or existing entity.
	 * @param array                 $payload Request body / query params.
	 *
	 * @return Verwerkingsactiviteit Hydrated entity (NOT yet persisted).
	 */
	private function hydrated(Verwerkingsactiviteit $entity, array $payload): Verwerkingsactiviteit {
		return (new ProcessingActivityHydrator())->hydrate(entity: $entity, payload: $payload);
	}//end hydrated()

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
				return $this->services->vrwMapper->find(id: (int)$identifier);
			} catch (DoesNotExistException $e) {
				return null;
			}
		}

		return $this->services->vrwMapper->resolveReference(reference: $identifier);
	}//end resolveOne()

	/**
	 * The 404 envelope for an activity the caller may not see or that does not exist.
	 *
	 * @param string $identifier The identifier as given.
	 *
	 * @return JSONResponse HTTP 404.
	 */
	private function notFound(string $identifier): JSONResponse {
		return $this->replies->notFound(error: 'Verwerkingsactiviteit not found', identifier: $identifier);
	}//end notFound()

	/**
	 * 403 envelope used by all admin-gated endpoints.
	 *
	 * @return JSONResponse Pre-baked 403 with explanatory message.
	 */
	private function forbidden(): JSONResponse {
		return $this->replies->forbidden(message: 'Admin privileges required to manage verwerkingsactiviteiten');
	}//end forbidden()
}//end class
