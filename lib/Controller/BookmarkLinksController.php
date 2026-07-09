<?php

/**
 * BookmarkLinksController — Tier-2 REST controller for Bookmarks links.
 *
 * Adds explicit link/create endpoints and an available-bookmarks picker
 * source so the picker UX can drive its modal without leaking NC
 * Bookmarks internals.
 *
 * Endpoints:
 *   - GET    /api/objects/{register}/{schema}/{id}/bookmarks              — list
 *   - POST   /api/objects/{register}/{schema}/{id}/bookmarks              — link existing
 *   - POST   /api/objects/{register}/{schema}/{id}/bookmarks/new          — create + link
 *   - DELETE /api/objects/{register}/{schema}/{id}/bookmarks/{bookmarkId} — unlink
 *   - GET    /api/integrations/bookmarks/available                        — picker source
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Bookmark links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class BookmarkLinksController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName             App id.
     * @param IRequest            $request             HTTP request.
     * @param BookmarkLinkService $bookmarkLinkService Backing service.
     * @param ObjectService       $objectService       OR object resolver.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly BookmarkLinkService $bookmarkLinkService,
        private readonly ObjectService $objectService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * List linked bookmarks for an object.
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
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Bookmarks app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $results = $this->bookmarkLinkService->getLinkedBookmarks($object->getUuid());

            return new JSONResponse(['results' => $results, 'total' => count($results)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end index()

    /**
     * Link an existing bookmark.
     *
     * Body: `{ bookmarkId: int }`.
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
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Bookmarks app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $bookmarkId = (int) $this->request->getParam('bookmarkId', 0);
            if ($bookmarkId === 0) {
                return new JSONResponse(['error' => 'bookmarkId is required'], 400);
            }

            $link = $this->bookmarkLinkService->linkBookmark(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $bookmarkId
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end link()

    /**
     * Create a new bookmark and link it.
     *
     * Body: `{ title, url, description?, tags?: [string] }`.
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function createNew(string $register, string $schema, string $id): JSONResponse
    {
        if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Bookmarks app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $title       = (string) $this->request->getParam('title', '');
            $url         = (string) $this->request->getParam('url', '');
            $description = (string) $this->request->getParam('description', '');
            $rawTags     = $this->request->getParam('tags', []);

            if (trim($title) === '') {
                return new JSONResponse(['error' => 'title is required'], 400);
            }

            if (trim($url) === '') {
                return new JSONResponse(['error' => 'url is required'], 400);
            }

            $tags = [];
            if (is_array($rawTags) === true) {
                foreach ($rawTags as $tag) {
                    if (is_string($tag) === true) {
                        $tags[] = $tag;
                    }
                }
            }

            $link = $this->bookmarkLinkService->createAndLinkBookmark(
                $object->getUuid(),
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $title,
                $url,
                $description,
                $tags
            );

            return new JSONResponse($link->jsonSerialize(), 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end createNew()

    /**
     * Unlink a bookmark.
     *
     * @param string $register   Register slug or id.
     * @param string $schema     Schema slug or id.
     * @param string $id         Object id.
     * @param string $bookmarkId NC Bookmarks bookmark id (numeric).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function destroy(string $register, string $schema, string $id, string $bookmarkId): JSONResponse
    {
        if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Bookmarks app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->bookmarkLinkService->unlinkBookmark($object->getUuid(), (int) $bookmarkId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return $this->mapException(exception: $e);
        }//end try
    }//end destroy()

    /**
     * List bookmarks visible to the current user (picker source).
     *
     * Query params: `search` — optional title/url substring;
     * `tags` — optional comma-separated tag filter.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @no-admin-idor-exempt Session-scoped list: returns bookmarks visible to the current user via the Bookmarks app; no caller-supplied object id.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-1
     */
    public function available(): JSONResponse
    {
        if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
            return new JSONResponse(
                ['error' => 'Nextcloud Bookmarks app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
                501
            );
        }

        $search = $this->request->getParam('search');
        if ($search !== null) {
            $search = (string) $search;
        }

        $tags    = null;
        $rawTags = $this->request->getParam('tags');
        if (is_string($rawTags) === true && $rawTags !== '') {
            $tags = array_values(
                array_filter(
                    array_map('trim', explode(',', $rawTags)),
                    static function (string $tag): bool {
                        return $tag !== '';
                    }
                )
            );
        } else if (is_array($rawTags) === true) {
            $tags = array_values(array_filter($rawTags, 'is_string'));
        }

        $bookmarks = $this->bookmarkLinkService->getAvailableBookmarks($search, $tags);
        return new JSONResponse(['results' => $bookmarks, 'total' => count($bookmarks)]);
    }//end available()

    /**
     * Resolve an OR object from register/schema/id.
     *
     * @param string $register Register slug or id.
     * @param string $schema   Schema slug or id.
     * @param string $id       Object id.
     *
     * @return ObjectEntity|null
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
     *   - 409 → conflict
     *   - 404 → not found
     *   - 503 → service unavailable
     *   - 400 → bad request
     *   - everything else → 400 bad request
     *
     * @param Exception $exception Source exception.
     *
     * @return JSONResponse
     */
    private function mapException(Exception $exception): JSONResponse
    {
        $code = $exception->getCode();
        if ($code === 409) {
            return new JSONResponse(['error' => $exception->getMessage()], 409);
        }

        if ($code === 404) {
            return new JSONResponse(['error' => $exception->getMessage()], 404);
        }

        if ($code === 503) {
            return new JSONResponse(['error' => $exception->getMessage()], 503);
        }

        if ($code === 400) {
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        }

        return new JSONResponse(['error' => $exception->getMessage()], 400);
    }//end mapException()
}//end class
