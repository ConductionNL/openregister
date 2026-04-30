<?php

/**
 * AVG / GDPR data-subject rights controller.
 *
 * Exposes the four DSAR endpoints as a thin wrapper around `DsarService`:
 *
 *   GET  /api/avg/inzage          — Art 15 right of access.
 *   POST /api/avg/rectificatie    — Art 16 right of rectification.
 *   POST /api/avg/vergetelheid    — Art 17 right to erasure.
 *   GET  /api/avg/portabiliteit   — Art 20 right to data portability.
 *
 * Authorization gate: every endpoint requires admin. DSAR operations
 * span the entire register surface and bypass per-schema RBAC; only
 * the operator (typically a privacy officer or a designated DSAR
 * handler) should be able to drive them. Operators wishing to
 * delegate DSAR handling should add their delegate to the `admin`
 * group or run a dedicated DSAR app.
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

use OCA\OpenRegister\Service\DsarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST endpoints for AVG data-subject rights.
 */
class DsarController extends Controller
{
    /**
     * Constructor.
     *
     * @param string        $appName      App identifier.
     * @param IRequest      $request      Active request.
     * @param DsarService   $dsarService  Composition service.
     * @param IUserSession  $userSession  Current user (admin gate).
     * @param IGroupManager $groupManager Group manager (admin gate).
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly DsarService $dsarService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * GET /api/avg/inzage — Art 15 right of access.
     *
     * Query parameters:
     *   - `subject` (required) — value to look up (email, BSN, name, …).
     *   - `type`    (optional) — restrict to a specific GdprEntity type.
     *   - `mode`    (optional) — `exact` (default) or `ilike` (substring).
     *
     * Returns:
     *   - 200 with `{subject, type, count, results}` on success.
     *   - 422 when `subject` is missing.
     *   - 403 when caller is not admin.
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    public function inzage(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return $this->forbidden();
        }

        $subject = (string) ($this->request->getParam(key: 'subject') ?? '');
        if ($subject === '') {
            return $this->missingSubject();
        }

        $type = $this->request->getParam(key: 'type');
        $mode = (string) ($this->request->getParam(key: 'mode') ?? 'exact');

        $results = $this->dsarService->findObjectsForSubject(
            subject: $subject,
            type: ($type !== null && $type !== '') ? (string) $type : null,
            mode: $mode
        );

        return new JSONResponse(
            data: [
                'subject' => $subject,
                'type'    => $type,
                'count'   => count($results),
                'results' => $results,
            ]
        );

    }//end inzage()

    /**
     * GET /api/avg/portabiliteit — Art 20 right to data portability.
     *
     * Same surface as `inzage` but the envelope is reduced to the
     * machine-readable export shape: only the object payloads + minimal
     * provenance, no GdprEntity match annotations.
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    public function portabiliteit(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return $this->forbidden();
        }

        $subject = (string) ($this->request->getParam(key: 'subject') ?? '');
        if ($subject === '') {
            return $this->missingSubject();
        }

        $type = $this->request->getParam(key: 'type');
        $mode = (string) ($this->request->getParam(key: 'mode') ?? 'exact');

        $results = $this->dsarService->findObjectsForSubject(
            subject: $subject,
            type: ($type !== null && $type !== '') ? (string) $type : null,
            mode: $mode
        );

        $exportObjects = array_map(static fn (array $row) => $row['object'], $results);

        return new JSONResponse(
            data: [
                'subject'   => $subject,
                'generated' => date('c'),
                'count'     => count($exportObjects),
                'objects'   => $exportObjects,
            ]
        );

    }//end portabiliteit()

    /**
     * POST /api/avg/vergetelheid — Art 17 right to erasure.
     *
     * Body parameters:
     *   - `subject` (required)
     *   - `type`    (optional)
     *   - `dryRun`  (optional bool — when true, returns matches without
     *                erasing; useful for confirmation UX before the
     *                operator clicks "really erase").
     *
     * The deletion itself is audit-logged for legal defence — the
     * configured DSAR processing activity provides the legal basis for
     * keeping the deletion record.
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    public function vergetelheid(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return $this->forbidden();
        }

        $subject = (string) ($this->request->getParam(key: 'subject') ?? '');
        if ($subject === '') {
            return $this->missingSubject();
        }

        $type   = $this->request->getParam(key: 'type');
        $dryRun = filter_var(
            $this->request->getParam(key: 'dryRun', default: false),
            FILTER_VALIDATE_BOOLEAN
        );

        $summary = $this->dsarService->eraseObjectsForSubject(
            subject: $subject,
            type: ($type !== null && $type !== '') ? (string) $type : null,
            dryRun: $dryRun
        );

        return new JSONResponse(data: $summary);

    }//end vergetelheid()

    /**
     * POST /api/avg/rectificatie — Art 16 right to rectification.
     *
     * Body parameters:
     *   - `objectId` (required, int) — internal id of the object to update.
     *   - `changes`  (required, object) — property → new value map.
     *
     * The update is attributed to the configured DSAR processing
     * activity via the per-action override on `ObjectEntity`.
     *
     * @return JSONResponse
     *
     * @NoCSRFRequired
     */
    public function rectificatie(): JSONResponse
    {
        if ($this->isAdmin() === false) {
            return $this->forbidden();
        }

        $objectId = (int) ($this->request->getParam(key: 'objectId') ?? 0);
        $changes  = $this->request->getParam(key: 'changes', default: []);
        if ($objectId === 0) {
            return new JSONResponse(
                data: ['error' => '`objectId` is required'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        if (is_array($changes) === false || $changes === []) {
            return new JSONResponse(
                data: ['error' => '`changes` must be a non-empty object'],
                statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        $updated = $this->dsarService->rectifyObjectForSubject(
            objectId: $objectId,
            changes: $changes
        );

        if ($updated === null) {
            return new JSONResponse(
                data: ['error' => 'Object not found or update failed', 'objectId' => $objectId],
                statusCode: Http::STATUS_NOT_FOUND
            );
        }

        return new JSONResponse(data: $updated);

    }//end rectificatie()

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

        return in_array(
            needle: 'admin',
            haystack: $this->groupManager->getUserGroupIds($user),
            strict: true
        );

    }//end isAdmin()

    /**
     * 403 envelope used by all admin-gated endpoints.
     *
     * @return JSONResponse Pre-baked 403.
     */
    private function forbidden(): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => 'Admin privileges required to drive DSAR operations'],
            statusCode: Http::STATUS_FORBIDDEN
        );

    }//end forbidden()

    /**
     * 422 envelope for missing `subject` parameter.
     *
     * @return JSONResponse Pre-baked 422.
     */
    private function missingSubject(): JSONResponse
    {
        return new JSONResponse(
            data: ['error' => '`subject` query parameter is required'],
            statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
        );

    }//end missingSubject()
}//end class
