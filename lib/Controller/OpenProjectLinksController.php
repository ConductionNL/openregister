<?php

/**
 * OpenProjectLinksController — Tier-2 REST controller for OpenProject
 * work-package links (external / OpenConnector-routed).
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/openproject            — list linked work packages
 *   - POST   /api/objects/{r}/{s}/{id}/openproject            — link existing work package `{workPackageId}`
 *   - POST   /api/objects/{r}/{s}/{id}/openproject/new        — create + link `{projectId, subject, type?}`
 *   - DELETE /api/objects/{r}/{s}/{id}/openproject/{wpId}     — unlink work package
 *   - GET    /api/integrations/openproject/available?search=  — work-package picker source
 *
 * OpenProject is external — the install dependency is OpenConnector
 * (which carries the `openproject` source + credentials, AD-4 / AD-22).
 * When the source is unconfigured / unreachable the service raises a
 * 503-with-cause Exception so the UI degrades to a "Configure" CTA
 * rather than a broken tab (wave-5.2 4-state auth UX).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OpenProjectLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 OpenProject links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class OpenProjectLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName       App id.
     * @param IRequest               $request       HTTP request.
     * @param OpenProjectLinkService $linkService   Backing service.
     * @param ObjectService          $objectService OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly OpenProjectLinkService $linkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked OpenProject work packages for an object.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->linkService->isOpenConnectorAvailable() === false) {
            return new JSONResponse(
                ['error' => 'OpenConnector app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $results = $this->linkService->getLinkedWorkPackages($object->getUuid());

            return new JSONResponse(
                [
                    'results' => $results,
                    'total'   => count($results),
                ]
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end index()

    /**
     * Link an existing OpenProject work package.
     *
     * Body: `{ workPackageId: int }`.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->linkService->isOpenConnectorAvailable() === false) {
            return new JSONResponse(
                ['error' => 'OpenConnector app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $workPackageId = (int) $this->request->getParam('workPackageId', 0);
            if ($workPackageId === 0) {
                return new JSONResponse(['error' => 'workPackageId is required'], 400);
            }

            $link = $this->linkService->linkWorkPackage(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $workPackageId
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Create a new OpenProject work package and link it.
     *
     * Body: `{ projectId: string, subject: string, type?: string }`.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function createAndLink(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->linkService->isOpenConnectorAvailable() === false) {
            return new JSONResponse(
                ['error' => 'OpenConnector app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $projectId = (string) $this->request->getParam('projectId', '');
            if (trim($projectId) === '') {
                return new JSONResponse(['error' => 'projectId is required'], 400);
            }

            $subject = (string) $this->request->getParam('subject', '');
            if (trim($subject) === '') {
                return new JSONResponse(['error' => 'subject is required'], 400);
            }

            $type = (string) $this->request->getParam('type', '');

            $link = $this->linkService->createAndLinkWorkPackage(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $projectId,
                $subject,
                $type
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end createAndLink()

    /**
     * Unlink an OpenProject work package.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     * @param string $wpId     Work-package id (numeric).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function destroy(string $register, string $schema, string $id, string $wpId): JSONResponse
    {
        if ($this->linkService->isOpenConnectorAvailable() === false) {
            return new JSONResponse(
                ['error' => 'OpenConnector app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->linkService->unlink($object->getUuid(), (int) $wpId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * List work packages reachable through the OpenConnector
     * `openproject` source (picker source).
     *
     * Query param: `search` — optional subject substring.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function available(): JSONResponse
    {
        if ($this->linkService->isOpenConnectorAvailable() === false) {
            return new JSONResponse(
                ['error' => 'OpenConnector app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $search = $this->request->getParam('search');
        if ($search !== null) {
            $search = (string) $search;
        }

        try {
            $workPackages = $this->linkService->getAvailableWorkPackages($search);
            return new JSONResponse(['results' => $workPackages, 'total' => count($workPackages)]);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }
    }//end available()

    /**
     * Resolve an OR object from register/schema/id.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return ObjectEntity|null
     *
     * @spec exclude Private helper: resolves an object from register/schema/id;
     *              the link REST contract is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
     */
    private function validateObject(string $register, string $schema, string $id): ?ObjectEntity
    {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end validateObject()

    /**
     * Map a service-layer Exception to a JSONResponse.
     *
     * Exception codes carry HTTP intent:
     *   - 400 → bad request
     *   - 401 → unauthorized (no user)
     *   - 404 → not found
     *   - 409 → conflict (duplicate link)
     *   - 502 → bad gateway (malformed upstream response)
     *   - 503 → service unavailable (source unconfigured / down)
     *   - everything else → 400 bad request
     *
     * @param Exception $exception Source exception.
     *
     * @return JSONResponse
     *
     * @spec exclude Private helper: maps a service exception code to an HTTP status;
     *              the link REST contract is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
     */
    private function mapException(Exception $exception): JSONResponse
    {
        $code = $exception->getCode();
        if (in_array($code, [400, 401, 404, 409, 502, 503], true) === true) {
            return new JSONResponse(['error' => $exception->getMessage()], $code);
        }

        return new JSONResponse(['error' => $exception->getMessage()], 400);
    }//end mapException()
}//end class
