<?php

/**
 * PhotoLinksController — Tier-2 REST controller for NC Photos album
 * links.
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/photos             — list linked albums
 *   - POST   /api/objects/{r}/{s}/{id}/photos             — link existing album `{albumId}`
 *   - POST   /api/objects/{r}/{s}/{id}/photos/new         — create + link album `{name}`
 *   - DELETE /api/objects/{r}/{s}/{id}/photos/{albumId}   — unlink album
 *   - GET    /api/integrations/photos/available?search=   — picker source
 *
 * NC Photos albums are user-scoped, so (unlike Flow) there is no admin
 * gate — the active session's user owns the albums it can link/create.
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
use OCA\OpenRegister\Service\PhotoLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Photos links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class PhotoLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName          App id.
     * @param IRequest         $request          HTTP request.
     * @param PhotoLinkService $photoLinkService Backing service.
     * @param ObjectService    $objectService    OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly PhotoLinkService $photoLinkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked Photos albums for an object.
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
     * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->photoLinkService->isPhotosAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Photos app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $results = $this->photoLinkService->getLinkedAlbums($object->getUuid());

            return new JSONResponse(
                    [
                        'results' => $results,
                        'total'   => count($results),
                    ]
                    );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end index()

    /**
     * Link an existing Photos album.
     *
     * Body: `{ albumId: int }`.
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
     * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->photoLinkService->isPhotosAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Photos app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $albumId = (int) $this->request->getParam('albumId', 0);
            if ($albumId === 0) {
                return new JSONResponse(['error' => 'albumId is required'], 400);
            }

            $link = $this->photoLinkService->linkAlbum(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $albumId
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Create a new Photos album and link it.
     *
     * Body: `{ name: string }`.
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
     * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
     */
    public function createAndLink(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->photoLinkService->isPhotosAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Photos app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $name = (string) $this->request->getParam('name', '');
            if (trim($name) === '') {
                return new JSONResponse(['error' => 'name is required'], 400);
            }

            $link = $this->photoLinkService->createAndLinkAlbum(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $name
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end createAndLink()

    /**
     * Unlink a Photos album.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     * @param string $albumId  Album id (numeric).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
     */
    public function destroy(string $register, string $schema, string $id, string $albumId): JSONResponse
    {
        if ($this->photoLinkService->isPhotosAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Photos app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->photoLinkService->unlinkAlbum($object->getUuid(), (int) $albumId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * List the current user's Photos albums (picker source).
     *
     * Query param: `search` — optional name substring.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt Session-scoped list: returns the current user's own Photos albums; no caller-supplied object id.
     *
     * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
     */
    public function available(): JSONResponse
    {
        if ($this->photoLinkService->isPhotosAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Photos app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $search = $this->request->getParam('search');
        if ($search !== null) {
            $search = (string) $search;
        }

        $albums = $this->photoLinkService->getAvailableAlbums($search);
        return new JSONResponse(['results' => $albums, 'total' => count($albums)]);
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
     * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
     *         than caught: every call site already wraps this helper and translates it to a 404.
     *         Swallowing it here would collapse "no such object" into the same null this method
     *         returns for other reasons, which the caller could no longer tell apart.
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
     *   - 503 → service unavailable
     *   - everything else → 400 bad request
     *
     * @param Exception $exception Source exception.
     *
     * @return JSONResponse
     */
    private function mapException(Exception $exception): JSONResponse
    {
        $code = $exception->getCode();
        if (in_array($code, [400, 401, 404, 409, 503], true) === true) {
            return new JSONResponse(['error' => $exception->getMessage()], $code);
        }

        return new JSONResponse(['error' => $exception->getMessage()], 400);
    }//end mapException()
}//end class
