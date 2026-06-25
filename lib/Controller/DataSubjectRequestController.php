<?php

/**
 * OpenRegister consumable GDPR data-subject-request controller.
 *
 * HTTP surface over {@see \OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService}
 * for leaf apps that drive data-subject rights over REST. Unlike
 * {@see \OCA\OpenRegister\Controller\DsarController} (admin-only, cross-tenant),
 * these endpoints are `@NoAdminRequired`: they run as the authenticated handler
 * and rely on the service's RBAC + tenant scoping so a caller only ever reaches
 * objects it is authorised to read or mutate.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * RBAC + tenant scoped data-subject-rights endpoints.
 */
class DataSubjectRequestController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                    $appName The app name.
     * @param IRequest                  $request The request.
     * @param DataSubjectRequestService $service The consumable DSR service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DataSubjectRequestService $service
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * GET /api/gdpr/subject-data — discover a subject's objects (art-15/20).
     *
     * Query params: `subject` (required), `type` (optional), `mode`
     * (`exact`|`ilike`). RBAC + tenant scoped.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/gdpr-data-subject-rights/tasks.md#task-2.2
     */
    public function subjectData(): JSONResponse
    {
        $subject = (string) ($this->request->getParam(key: 'subject') ?? '');
        if ($subject === '') {
            return $this->missingSubject();
        }

        $results = $this->service->findSubjectData(
            subjectId: $subject,
            type: $this->optionalType(),
            mode: (string) ($this->request->getParam(key: 'mode') ?? 'exact')
        );

        return new JSONResponse(
            data: [
                'subject' => $subject,
                'count'   => count($results),
                'results' => $results,
            ]
        );

    }//end subjectData()

    /**
     * GET /api/gdpr/access-export — portable export of a subject's data (art-15/20).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/gdpr-data-subject-rights/tasks.md#task-2.3
     */
    public function accessExport(): JSONResponse
    {
        $subject = (string) ($this->request->getParam(key: 'subject') ?? '');
        if ($subject === '') {
            return $this->missingSubject();
        }

        $bundle = $this->service->assembleAccessExport(
            subjectId: $subject,
            type: $this->optionalType()
        );

        return new JSONResponse(data: $bundle);

    }//end accessExport()

    /**
     * POST /api/gdpr/rectify — correct a single object (art-16).
     *
     * Body: `object` (id/uuid, required), `changes` (object, required).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/gdpr-data-subject-rights/tasks.md#task-2.4
     */
    public function rectify(): JSONResponse
    {
        $objectId = (string) ($this->request->getParam(key: 'object') ?? '');
        $changes  = $this->request->getParam(key: 'changes');
        if ($objectId === '' || is_array($changes) === false) {
            return $this->badRequest(message: 'object and changes are required');
        }

        $result = $this->service->rectify(objectIdentifier: $objectId, changes: $changes);
        if ($result === null) {
            return new JSONResponse(data: ['error' => 'Object not found, not authorised, or immutable'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: $result);

    }//end rectify()

    /**
     * POST /api/gdpr/erase — erase a subject's data (art-17).
     *
     * Body: `subject` (required), `type` (optional), `eraseMode`
     * (`pseudonymise`|`whole-object`), `dryRun` (bool). Objects under legal
     * hold / immutable status are reported as `held`, never erased.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/gdpr-data-subject-rights/tasks.md#task-2.5
     */
    public function erase(): JSONResponse
    {
        $subject = (string) ($this->request->getParam(key: 'subject') ?? '');
        if ($subject === '') {
            return $this->missingSubject();
        }

        $summary = $this->service->erase(
            subjectId: $subject,
            type: $this->optionalType(),
            eraseMode: (string) ($this->request->getParam(key: 'eraseMode') ?? DataSubjectRequestService::ERASE_MODE_PSEUDONYMISE),
            dryRun: filter_var($this->request->getParam(key: 'dryRun'), FILTER_VALIDATE_BOOLEAN)
        );

        return new JSONResponse(data: $summary);

    }//end erase()

    /**
     * POST /api/gdpr/restrict — set/clear processing restriction (art-18).
     *
     * Body: `object` (required), `restricted` (bool), `reason` (optional).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/gdpr-data-subject-rights/tasks.md#task-2.6
     */
    public function restrict(): JSONResponse
    {
        $objectId = (string) ($this->request->getParam(key: 'object') ?? '');
        if ($objectId === '') {
            return $this->badRequest(message: 'object is required');
        }

        $result = $this->service->setRestriction(
            objectIdentifier: $objectId,
            restricted: filter_var($this->request->getParam(key: 'restricted'), FILTER_VALIDATE_BOOLEAN),
            reason: (string) ($this->request->getParam(key: 'reason') ?? '')
        );
        if ($result === null) {
            return new JSONResponse(data: ['error' => 'Object not found, not authorised, or immutable'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: $result);

    }//end restrict()

    /**
     * POST /api/gdpr/object — set/clear objection (art-21).
     *
     * Body: `object` (required), `objected` (bool), `reason` (optional).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/gdpr-data-subject-rights/tasks.md#task-2.6
     */
    public function objection(): JSONResponse
    {
        $objectId = (string) ($this->request->getParam(key: 'object') ?? '');
        if ($objectId === '') {
            return $this->badRequest(message: 'object is required');
        }

        $result = $this->service->setObjection(
            objectIdentifier: $objectId,
            objected: filter_var($this->request->getParam(key: 'objected'), FILTER_VALIDATE_BOOLEAN),
            reason: (string) ($this->request->getParam(key: 'reason') ?? '')
        );
        if ($result === null) {
            return new JSONResponse(data: ['error' => 'Object not found, not authorised, or immutable'], statusCode: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(data: $result);

    }//end objection()

    /**
     * Resolve the optional `type` query/body parameter to a string|null.
     *
     * @return string|null
     */
    private function optionalType(): ?string
    {
        $type = $this->request->getParam(key: 'type');
        if ($type === null || $type === '') {
            return null;
        }

        return (string) $type;

    }//end optionalType()

    /**
     * 422 — the required `subject` parameter is missing.
     *
     * @return JSONResponse
     */
    private function missingSubject(): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Missing required parameter: subject'],
            statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
        );

    }//end missingSubject()

    /**
     * 400 — a required body parameter is missing/invalid.
     *
     * @param string $message Human-readable reason.
     *
     * @return JSONResponse
     */
    private function badRequest(string $message): JSONResponse
    {
        return new JSONResponse(data: ['error' => $message], statusCode: Http::STATUS_BAD_REQUEST);

    }//end badRequest()
}//end class
