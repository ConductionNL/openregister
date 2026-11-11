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
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\BookmarkLinksControllerServices;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\BookmarkDraft;
use OCA\OpenRegister\Service\BookmarkLinkServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Bookmark links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class BookmarkLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var BookmarkLinkServiceInterface
	 */
	private readonly BookmarkLinkServiceInterface $bookmarkLinkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param BookmarkLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		BookmarkLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->bookmarkLinkService = $services->bookmarkLinkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List linked bookmarks for an object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->indexFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * List linked bookmarks for an object: the body of index() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function indexFor(ObjectRef $ref): JSONResponse {
		if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Bookmarks');
		}

		return $this->listLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->bookmarkLinkService->getLinkedBookmarks($object->getUuid()))
		);
	}//end indexFor()

	/**
	 * Link an existing bookmark.
	 *
	 * Body: `{ bookmarkId: int }`.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		return $this->linkFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * Link an existing bookmark: the body of link() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function linkFor(ObjectRef $ref): JSONResponse {
		if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Bookmarks');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object): JSONResponse {
				$bookmarkId = (int)$this->request->getParam('bookmarkId', 0);
				if ($bookmarkId === 0) {
					return new JSONResponse(['error' => 'bookmarkId is required'], 400);
				}

				$link = $this->bookmarkLinkService->linkBookmark(
					$this->linkTargetOf(object: $object),
					$bookmarkId
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end linkFor()

	/**
	 * Create a new bookmark and link it.
	 *
	 * Body: `{ title, url, description?, tags?: [string] }`.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function createNew(string $register, string $schema, string $id): JSONResponse {
		return $this->createNewFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end createNew()

	/**
	 * Create a new bookmark and link it: the body of createNew() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function createNewFor(ObjectRef $ref): JSONResponse {
		if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Bookmarks');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: fn (ObjectEntity $object): JSONResponse => $this->createBookmarkFor(object: $object)
		);
	}//end createNewFor()

	/**
	 * Create the requested bookmark and link it to the object.
	 *
	 * @param ObjectEntity $object The object the bookmark is linked to.
	 *
	 * @return JSONResponse 201 with the link, or 400 when the title or url is missing.
	 */
	private function createBookmarkFor(ObjectEntity $object): JSONResponse {
		$title = (string)$this->request->getParam('title', '');
		$url = (string)$this->request->getParam('url', '');
		$description = (string)$this->request->getParam('description', '');
		$tags = $this->textTags(rawTags: $this->request->getParam('tags', []));

		if (trim($title) === '') {
			return LinkedSourceResponses::required(param: 'title');
		}

		if (trim($url) === '') {
			return LinkedSourceResponses::required(param: 'url');
		}

		$link = $this->bookmarkLinkService->createAndLinkBookmark(
			$this->linkTargetOf(object: $object),
			(new BookmarkDraft(title: $title, url: $url))->withDetails(description: $description, tags: $tags)
		);

		return LinkedSourceResponses::created(link: $link);
	}//end createBookmarkFor()

	/**
	 * The text entries of the requested tags; anything that is not a list is no tags.
	 *
	 * @param mixed $rawTags The `tags` of the request.
	 *
	 * @return array<int, string> The tags.
	 */
	private function textTags(mixed $rawTags): array {
		$tags = [];
		if (is_array($rawTags) === true) {
			foreach ($rawTags as $tag) {
				if (is_string($tag) === true) {
					$tags[] = $tag;
				}
			}
		}

		return $tags;
	}//end textTags()

	/**
	 * Unlink a bookmark.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $bookmarkId NC Bookmarks bookmark id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(string $register, string $schema, string $id, string $bookmarkId): JSONResponse {
		return $this->destroyFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id), bookmarkId: $bookmarkId);
	}//end destroy()

	/**
	 * Unlink a bookmark: the body of destroy() for the object its route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 * @param string $bookmarkId NC Bookmarks bookmark id (numeric).
	 *
	 * @return JSONResponse
	 */
	private function destroyFor(ObjectRef $ref, string $bookmarkId): JSONResponse {
		if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Bookmarks');
		}

		return $this->onLinkedObject(
			route: $this->routeOf(ref: $ref),
			action: function (ObjectEntity $object) use ($bookmarkId): JSONResponse {
				$this->bookmarkLinkService->unlinkBookmark($object->getUuid(), (int)$bookmarkId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroyFor()

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
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function available(): JSONResponse {
		if ($this->bookmarkLinkService->isBookmarksAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Bookmarks');
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$tags = null;
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
		} elseif (is_array($rawTags) === true) {
			$tags = array_values(array_filter($rawTags, 'is_string'));
		}

		$bookmarks = $this->bookmarkLinkService->getAvailableBookmarks($search, $tags);
		return new JSONResponse(['results' => $bookmarks, 'total' => count($bookmarks)]);
	}//end available()

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
	protected function mapException(Exception $exception): JSONResponse {
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

	/**
	 * The link route of the object a route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return ObjectLinkRoute The route the link traits resolve.
	 */
	private function routeOf(ObjectRef $ref): ObjectLinkRoute {
		return new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id);
	}//end routeOf()
}//end class
