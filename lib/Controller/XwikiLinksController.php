<?php

/**
 * XwikiLinksController — Tier-2 REST controller for remote xWiki page
 * links (external, OpenConnector-routed).
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/xwiki            — list linked pages
 *   - POST   /api/objects/{r}/{s}/{id}/xwiki            — link existing page `{pageReference}`
 *   - POST   /api/objects/{r}/{s}/{id}/xwiki/new        — create + link page `{space,title}`
 *   - DELETE /api/objects/{r}/{s}/{id}/xwiki/{pageRef}  — unlink page (ref is url-encoded)
 *   - GET    /api/integrations/xwiki/available?search=  — browse remote xWiki pages (picker)
 *
 * Unlike the NC-native Tier-2 leaves, xWiki is external: there is no
 * local NC app to gate on. The OpenConnector app carries the `xwiki`
 * source + credentials; when it (or the source) is missing the service
 * surfaces the 4-state cause (`openconnector-down` /
 * `openconnector-source-missing` / `provider-auth` /
 * `upstream-service-down`) which this controller relays as a 503 with
 * `details.cause` so the UI renders the right banner (AD-23 / wave-5.1).
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
use OCA\OpenRegister\Service\XwikiLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 xWiki links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class XwikiLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName          App id.
     * @param IRequest         $request          HTTP request.
     * @param XwikiLinkService $xwikiLinkService Backing service.
     * @param ObjectService    $objectService    OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly XwikiLinkService $xwikiLinkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked xWiki pages for an object.
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
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $results = $this->xwikiLinkService->getLinkedPages($object->getUuid());

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
     * Link an existing remote xWiki page.
     *
     * Body: `{ pageReference: string }` (full URL or `Space.Page`).
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
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $pageReference = (string) $this->request->getParam('pageReference', '');
            if (trim($pageReference) === '') {
                return new JSONResponse(['error' => 'pageReference is required'], 400);
            }

            $link = $this->xwikiLinkService->linkPage(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $pageReference
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Create a new remote xWiki page and link it.
     *
     * Body: `{ space: string, title: string }`.
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
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $space = (string) $this->request->getParam('space', '');
            if (trim($space) === '') {
                return new JSONResponse(['error' => 'space is required'], 400);
            }

            $title = (string) $this->request->getParam('title', '');
            if (trim($title) === '') {
                return new JSONResponse(['error' => 'title is required'], 400);
            }

            $link = $this->xwikiLinkService->createAndLinkPage(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $space,
                $title
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end createAndLink()

    /**
     * Unlink an xWiki page.
     *
     * The page reference is carried url-encoded in the path (it may contain
     * dots, colons or slashes); NC decodes the route segment before it
     * reaches here.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     * @param string $pageRef  Url-encoded canonical xWiki page reference.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function destroy(string $register, string $schema, string $id, string $pageRef): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $pageReference = rawurldecode($pageRef);
            $this->xwikiLinkService->unlinkPage($object->getUuid(), $pageReference);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * Browse the remote xWiki pages available to link (picker source).
     *
     * Query param: `search` — optional title/reference substring.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt Capability/config probe: reports whether the admin-configured XWiki OpenConnector source is reachable;
     *   no caller-supplied per-object resource.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
     */
    public function available(): JSONResponse
    {
        $search = $this->request->getParam('search');
        if ($search !== null) {
            $search = (string) $search;
        }

        $result = $this->xwikiLinkService->getAvailablePages($search);

        // Unconfigured-source / upstream-down → 503 with details.cause so
        // the picker renders its Configure-XWiki CTA (4-state UX).
        if (($result['unavailable'] ?? false) === true) {
            return new JSONResponse(
                [
                    'error'   => 'xWiki source is not available',
                    'details' => ['cause' => $result['cause']],
                ],
                503
            );
        }

        return new JSONResponse(['results' => $result['results'], 'total' => $result['total']]);
    }//end available()

    /**
     * Free-text search the remote xWiki knowledge base (object-independent).
     *
     * The query-first surface a consuming app (e.g. a knowledge-base dashboard
     * widget) calls to find xWiki pages by free text, with no OR object context.
     * The xWiki base URL is resolved entirely from the OpenConnector `xwiki`
     * source — the caller never holds a direct xWiki URL.
     *
     * Query params:
     *   - `q` / `search` — free-text query (optional; empty lists available pages)
     *   - `limit`        — max results (clamped 1..100, default 25)
     *   - `offset`       — pagination offset (clamped >= 0, default 0)
     *
     * Read-only and null-safe: an unconfigured `xwiki` source / unreachable
     * upstream returns 503 with `details.cause` (the 4-state UX), never a fatal.
     *
     * @return JSONResponse `{ results, total, limit, offset }` on success, or a
     *                      503 `{ error, details: { cause } }` when the source is
     *                      unconfigured/down.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt No per-object resource: object-independent free-text knowledge-base search proxied to the
     *   admin-configured XWiki source; takes no OpenRegister object id.
     *
     * @spec openspec/changes/integration-xwiki-query-search/specs/integration-xwiki/spec.md
     */
    public function search(): JSONResponse
    {
        $query = $this->request->getParam('q');
        if ($query === null) {
            $query = $this->request->getParam('search');
        }

        if ($query !== null) {
            $query = (string) $query;
        }

        $limit  = (int) $this->request->getParam('limit', 25);
        $offset = (int) $this->request->getParam('offset', 0);

        $result = $this->xwikiLinkService->searchPages($query, $limit, $offset);

        // Unconfigured-source / upstream-down → 503 with details.cause so the
        // consumer renders its Configure-XWiki / unavailable state (4-state UX).
        if (($result['unavailable'] ?? false) === true) {
            return new JSONResponse(
                [
                    'error'   => 'xWiki source is not available',
                    'details' => ['cause' => $result['cause']],
                ],
                503
            );
        }

        return new JSONResponse(
            [
                'results' => $result['results'],
                'total'   => $result['total'],
                'limit'   => $result['limit'],
                'offset'  => $result['offset'],
            ]
        );
    }//end search()

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
     *   the link REST contract is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
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
     * The service encodes an unconfigured-source / upstream failure as a
     * 503 whose message is `xwiki:<cause>`; this method unwraps that into a
     * `details.cause` payload the UI's 4-state banner consumes. All other
     * codes carry plain HTTP intent.
     *
     * @param Exception $exception Source exception.
     *
     * @return JSONResponse
     *
     * @spec exclude Private helper: maps a service exception code to an HTTP status;
     *   the link REST contract is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
     */
    private function mapException(Exception $exception): JSONResponse
    {
        $code    = $exception->getCode();
        $message = $exception->getMessage();

        if ($code === 503 && str_starts_with($message, 'xwiki:') === true) {
            $cause = substr($message, strlen('xwiki:'));
            return new JSONResponse(
                [
                    'error'   => 'xWiki source is not available',
                    'details' => ['cause' => $cause],
                ],
                503
            );
        }

        if (in_array($code, [400, 401, 404, 409, 503], true) === true) {
            return new JSONResponse(['error' => $message], $code);
        }

        return new JSONResponse(['error' => $message], 400);
    }//end mapException()
}//end class
