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
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
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
 */
class VerwerkingsactiviteitenController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                      $appName      App identifier.
     * @param IRequest                    $request      Active request.
     * @param VerwerkingsactiviteitMapper $vrwMapper    Mapper for the catalog.
     * @param IUserSession                $userSession  Current user session.
     * @param IGroupManager               $groupManager Group manager (admin gate).
     * @param IDBConnection               $db           DB for the verantwoording aggregation.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly VerwerkingsactiviteitMapper $vrwMapper,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IDBConnection $db,
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
     */
    public function index(): JSONResponse
    {
        $status         = $this->request->getParam(key: 'status');
        $organisationId = $this->request->getParam(key: 'organisation');

        $rows = $this->vrwMapper->findAll(
            organisationId: ($organisationId !== null && $organisationId !== '') ? (string) $organisationId : null,
            status: ($status !== null && $status !== '') ? (string) $status : null
        );

        return new JSONResponse(
            data: [
                'count'   => count($rows),
                'results' => array_map(static fn (Verwerkingsactiviteit $a) => $a->jsonSerialize(), $rows),
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
     */
    public function show(string $id): JSONResponse
    {
        $entity = $this->resolveOne(identifier: $id);
        if ($entity === null) {
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
     */
    public function create(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return $this->forbidden();
        }

        $payload = (array) ($this->request->getParams() ?? []);

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
     */
    public function update(string $id): JSONResponse
    {
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

        $payload = (array) ($this->request->getParams() ?? []);

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
     */
    public function destroy(string $id): JSONResponse
    {
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
     */
    public function verantwoording(): JSONResponse
    {
        $activities = $this->vrwMapper->findAll();

        $auditCounts = $this->aggregateAuditCounts(
            uuids: array_map(static fn (Verwerkingsactiviteit $a) => (string) $a->getUuid(), $activities)
        );

        $payload = [];
        foreach ($activities as $activity) {
            $row = $activity->jsonSerialize();
            $row['activity'] = $auditCounts[(string) $activity->getUuid()] ?? [
                'totalEvents' => 0,
                'byAction'    => [],
            ];
            $payload[]       = $row;
        }

        return new JSONResponse(
            data: [
                'count'      => count($payload),
                'activities' => $payload,
            ]
        );

    }//end verantwoording()

    /**
     * Hydrate (or update) a Verwerkingsactiviteit from a request payload.
     *
     * @param Verwerkingsactiviteit $entity  Fresh or existing entity.
     * @param array                 $payload Request body / query params.
     *
     * @return Verwerkingsactiviteit Hydrated entity (NOT yet persisted).
     */
    private function hydrateFromPayload(Verwerkingsactiviteit $entity, array $payload): Verwerkingsactiviteit
    {
        $stringFields = [
            'code'                        => 'setCode',
            'naam'                        => 'setNaam',
            'beschrijving'                => 'setBeschrijving',
            'doelbinding'                 => 'setDoelbinding',
            'rechtsgrond'                 => 'setRechtsgrond',
            'bewaartermijn'               => 'setBewaartermijn',
            'technischeMaatregelen'       => 'setTechnischeMaatregelen',
            'organisatorischeMaatregelen' => 'setOrganisatorischeMaatregelen',
            'organisationId'              => 'setOrganisationId',
            'status'                      => 'setStatus',
        ];
        foreach ($stringFields as $field => $setter) {
            if (array_key_exists($field, $payload) === true) {
                $entity->{$setter}(($payload[$field] === null) ? null : (string) $payload[$field]);
            }
        }

        $arrayFields = [
            'categorieenBetrokkenen'       => 'setCategorieenBetrokkenen',
            'categorieenPersoonsgegevens'  => 'setCategorieenPersoonsgegevens',
            'ontvangers'                   => 'setOntvangers',
            'doorgifteBuitenEu'            => 'setDoorgifteBuitenEu',
            'verwerkingsverantwoordelijke' => 'setVerwerkingsverantwoordelijke',
            'contactgegevensFg'            => 'setContactgegevensFg',
        ];
        foreach ($arrayFields as $field => $setter) {
            if (array_key_exists($field, $payload) === true) {
                $value    = $payload[$field];
                $hydrated = (is_array($value) === true) ? $value : null;
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
    private function resolveOne(string $identifier): ?Verwerkingsactiviteit
    {
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier) === true) {
            try {
                return $this->vrwMapper->find(id: (int) $identifier);
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
    private function aggregateAuditCounts(array $uuids): array
    {
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
            $rows   = $result->fetchAll();
            $result->closeCursor();
        } catch (\Exception $e) {
            return [];
        }

        $aggregate = [];
        foreach ($rows as $row) {
            $uuid   = (string) ($row['processing_activity_id'] ?? '');
            $action = (string) ($row['action'] ?? '');
            $count  = (int) ($row['cnt'] ?? 0);
            $aggregate[$uuid] ??= ['totalEvents' => 0, 'byAction' => []];
            $aggregate[$uuid]['byAction'][$action] = $count;
            $aggregate[$uuid]['totalEvents']      += $count;
        }

        return $aggregate;

    }//end aggregateAuditCounts()

    /**
     * Whether the active user is in the `admin` group.
     *
     * @return bool True when the active user is in the admin group.
     */
    private function isAdmin(): bool
    {
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
     * 403 envelope used by all admin-gated endpoints.
     *
     * @return JSONResponse Pre-baked 403 with explanatory message.
     */
    private function forbidden(): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Admin privileges required to manage verwerkingsactiviteiten'],
            statusCode: Http::STATUS_FORBIDDEN
        );

    }//end forbidden()
}//end class
