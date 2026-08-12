<?php

/**
 * MapLinksController — Tier-2 REST controller for NC Maps favorite
 * (POI) links.
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/maps               — list linked POIs
 *   - POST   /api/objects/{r}/{s}/{id}/maps               — link existing POI `{favoriteId}`
 *   - POST   /api/objects/{r}/{s}/{id}/maps/new           — create + link POI
 *   - DELETE /api/objects/{r}/{s}/{id}/maps/{favoriteId}  — unlink POI
 *   - GET    /api/integrations/maps/available?search=     — picker source
 *
 * NC Maps favorites are user-scoped, so (unlike Flow) there is no admin
 * gate — the active session's user owns the favorites it can
 * link/create.
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
use OCA\OpenRegister\Service\MapLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Maps links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class MapLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string         $appName        App id.
     * @param IRequest       $request        HTTP request.
     * @param MapLinkService $mapLinkService Backing service.
     * @param ObjectService  $objectService  OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MapLinkService $mapLinkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked Maps POIs for an object.
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
        if ($this->mapLinkService->isMapsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Maps app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $results = $this->mapLinkService->getLinkedPois($object->getUuid());

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
     * Link an existing Maps POI.
     *
     * Body: `{ favoriteId: int }`.
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
        if ($this->mapLinkService->isMapsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Maps app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $favoriteId = (int) $this->request->getParam('favoriteId', 0);
            if ($favoriteId === 0) {
                return new JSONResponse(['error' => 'favoriteId is required'], 400);
            }

            $link = $this->mapLinkService->linkPoi(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $favoriteId
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Create a new Maps POI and link it.
     *
     * Body: `{ name: string, lat: float, lng: float, category?: string, comment?: string }`.
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
        if ($this->mapLinkService->isMapsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Maps app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $params = $this->parsePoiRequestParams();
            if ($params instanceof JSONResponse) {
                return $params;
            }

            [$name, $lat, $lng, $category, $comment] = $params;

            $link = $this->mapLinkService->createAndLinkPoi(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $name,
                $lat,
                $lng,
                $category,
                $comment
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end createAndLink()

    /**
     * Parse and validate the POI creation request parameters.
     *
     * Returns a JSONResponse with the appropriate error status on validation failure,
     * or a tuple array `[name, lat, lng, category, comment]` on success.
     *
     * @return array{0: string, 1: float, 2: float, 3: string|null, 4: string|null}|JSONResponse
     */
    private function parsePoiRequestParams(): array|JSONResponse
    {
        $name = (string) $this->request->getParam('name', '');
        if (trim($name) === '') {
            return new JSONResponse(['error' => 'name is required'], 400);
        }

        $latParam = $this->request->getParam('lat');
        $lngParam = $this->request->getParam('lng');
        if ($latParam === null || $latParam === '' || $lngParam === null || $lngParam === '') {
            return new JSONResponse(['error' => 'lat and lng are required'], 400);
        }

        $category = $this->request->getParam('category');
        if ($category !== null) {
            $category = (string) $category;
        }

        $comment = $this->request->getParam('comment');
        if ($comment !== null) {
            $comment = (string) $comment;
        }

        return [$name, (float) $latParam, (float) $lngParam, $category, $comment];
    }//end parsePoiRequestParams()

    /**
     * Unlink a Maps POI.
     *
     * @param string $register   Register slug or id.
     * @param string $schema     Schema slug or id.
     * @param string $id         Object id.
     * @param string $favoriteId Favorite id (numeric).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function destroy(string $register, string $schema, string $id, string $favoriteId): JSONResponse
    {
        if ($this->mapLinkService->isMapsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Maps app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->mapLinkService->unlinkPoi($object->getUuid(), (int) $favoriteId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * List the current user's Maps POIs (picker source).
     *
     * Query param: `search` — optional name substring.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt Session-scoped list: returns the current user's own Maps POIs; no caller-supplied object id.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function available(): JSONResponse
    {
        if ($this->mapLinkService->isMapsAvailable() === false) {
            return new JSONResponse(
                ['error' => 'NC Maps app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $search = $this->request->getParam('search');
        if ($search !== null) {
            $search = (string) $search;
        }

        $pois = $this->mapLinkService->getAvailablePois($search);
        return new JSONResponse(['results' => $pois, 'total' => count($pois)]);
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
     * @spec exclude Private helper: resolves an object from register/schema/id; the link REST contract is owned by
     *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
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
     *
     * @spec exclude Private helper: maps a service exception code to an HTTP status; the link REST contract is owned
     *              by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
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
