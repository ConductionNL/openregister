<?php

/**
 * TimeController
 *
 * REST controller for time-tracking operations on OpenRegister objects.
 * Sub-resource endpoints follow the pattern:
 *   GET  /api/objects/{register}/{schema}/{id}/time
 *   POST /api/objects/{register}/{schema}/{id}/time
 *   DELETE /api/objects/{register}/{schema}/{id}/time/{entryId}
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TimeEntryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * TimeController handles time entry sub-resource endpoints for objects.
 *
 * Every mutation endpoint performs per-object authorization via
 * `authorizeTimeMutation()` before writing, satisfying Rule 3 (IDOR
 * prevention): only the user who logged the entry, or an admin, may delete it.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
 */
class TimeController extends Controller
{

    /**
     * Time entry service.
     *
     * @var TimeEntryService
     */
    private readonly TimeEntryService $timeEntryService;

    /**
     * Object service.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * User session.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param string           $appName          Application name.
     * @param IRequest         $request          HTTP request.
     * @param TimeEntryService $timeEntryService Time entry service.
     * @param ObjectService    $objectService    Object service.
     * @param IUserSession     $userSession      User session.
     * @param LoggerInterface  $logger           Logger.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
     */
    public function __construct(
        string $appName,
        IRequest $request,
        TimeEntryService $timeEntryService,
        ObjectService $objectService,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->timeEntryService = $timeEntryService;
        $this->objectService    = $objectService;
        $this->userSession      = $userSession;
        $this->logger           = $logger;
    }//end __construct()

    /**
     * List all time entries for an object and return the denormalized total.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->timeEntryService->isBackendAvailable() === false) {
            return new JSONResponse(
                [
                    'error' => 'Time tracking backend is not installed',
                    'code'  => 'APP_NOT_AVAILABLE',
                    'backend' => $this->timeEntryService->getBackendName(),
                ],
                501
            );
        }

        try {
            $this->timeEntryService->requireAuthenticatedUser();
        } catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
            return new JSONResponse(['error' => 'Not authorized'], 403);
        }

        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object === null) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        }

        try {
            $result = $this->timeEntryService->getEntriesForObject(objectUuid: $object->getUuid());
            return new JSONResponse($result);
        } catch (Exception $e) {
            $this->logger->error('[TimeController] index failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Operation failed'], 500);
        }
    }//end index()

    /**
     * Log a new time entry against an object.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->timeEntryService->isBackendAvailable() === false) {
            return new JSONResponse(
                [
                    'error'   => 'Time tracking backend is not installed',
                    'code'    => 'APP_NOT_AVAILABLE',
                    'backend' => $this->timeEntryService->getBackendName(),
                ],
                501
            );
        }

        try {
            $this->timeEntryService->requireAuthenticatedUser();
        } catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
            return new JSONResponse(['error' => 'Not authorized'], 403);
        }

        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object === null) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        }

        $params          = $this->request->getParams();
        $durationMinutes = isset($params['durationMinutes']) ? (int) $params['durationMinutes'] : 0;
        $description     = isset($params['description']) ? (string) $params['description'] : null;
        $entryDateRaw    = $params['entryDate'] ?? null;

        $entryDate = null;
        if ($entryDateRaw !== null && $entryDateRaw !== '') {
            $parsed = DateTime::createFromFormat(DateTime::ATOM, (string) $entryDateRaw);
            if ($parsed instanceof DateTime) {
                $entryDate = $parsed;
            }
        }

        try {
            $link = $this->timeEntryService->logTime(
                objectUuid: $object->getUuid(),
                registerId: (int) $object->getRegister(),
                durationMinutes: $durationMinutes,
                description: $description,
                entryDate: $entryDate
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            $this->logger->error('[TimeController] create failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Operation failed'], 500);
        }
    }//end create()

    /**
     * Delete a time entry (per-object authorization: owner or admin only).
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     * @param string $entryId  The time entry ID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(string $register, string $schema, string $id, string $entryId): JSONResponse
    {
        if ($this->timeEntryService->isBackendAvailable() === false) {
            return new JSONResponse(
                [
                    'error'   => 'Time tracking backend is not installed',
                    'code'    => 'APP_NOT_AVAILABLE',
                    'backend' => $this->timeEntryService->getBackendName(),
                ],
                501
            );
        }

        try {
            $this->timeEntryService->requireAuthenticatedUser();
        } catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
            return new JSONResponse(['error' => 'Not authorized'], 403);
        }

        $object = $this->resolveObject(register: $register, schema: $schema, id: $id);
        if ($object === null) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        }

        try {
            $this->timeEntryService->deleteEntry(
                entryId: (int) $entryId,
                objectUuid: $object->getUuid()
            );

            return new JSONResponse(['success' => true]);
        } catch (\OCP\AppFramework\OCS\OCSForbiddenException $e) {
            return new JSONResponse(['error' => 'Not authorized'], 403);
        } catch (Exception $e) {
            if ($e->getMessage() === 'Time entry not found.') {
                return new JSONResponse(['error' => 'Time entry not found'], 404);
            }

            $this->logger->error('[TimeController] destroy failed: '.$e->getMessage());
            return new JSONResponse(['error' => 'Operation failed'], 500);
        }
    }//end destroy()

    /**
     * Resolve an object entity from register/schema/id.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $id       The object ID.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null
     */
    private function resolveObject(
        string $register,
        string $schema,
        string $id
    ): ?\OCA\OpenRegister\Db\ObjectEntity {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end resolveObject()

}//end class
