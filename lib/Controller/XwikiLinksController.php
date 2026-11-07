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
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\XwikiLink;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\XwikiLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 xWiki links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class XwikiLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param XwikiLinkServiceInterface $xwikiLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly XwikiLinkServiceInterface $xwikiLinkService,
		private readonly ObjectOperationsInterface $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List linked xWiki pages for an object.
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
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		$action = $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->xwikiLinkService->getLinkedPages($object->getUuid()));

		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
	}//end index()

	/**
	 * Link an existing remote xWiki page.
	 *
	 * Body: `{ pageReference: string }` (full URL or `Space.Page`).
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
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		$action = LinkedSourceActions::linkByTexts(request: $this->request, params: ['pageReference'], link: $this->linkPage(...));

		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
	}//end link()

	/**
	 * Create a new remote xWiki page and link it.
	 *
	 * Body: `{ space: string, title: string }`.
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
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function createAndLink(string $register, string $schema, string $id): JSONResponse {
		$action = LinkedSourceActions::linkByTexts(request: $this->request, params: ['space', 'title'], link: $this->createPage(...));

		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
	}//end createAndLink()

	/**
	 * Unlink an xWiki page.
	 *
	 * The page reference is carried url-encoded in the path (it may contain
	 * dots, colons or slashes); NC decodes the route segment before it
	 * reaches here.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $pageRef Url-encoded canonical xWiki page reference.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function destroy(string $register, string $schema, string $id, string $pageRef): JSONResponse {
		$action = LinkedSourceActions::unlink(unlink: fn (string $uuid) => $this->xwikiLinkService->unlinkPage($uuid, rawurldecode($pageRef)));

		return $this->onLinkedObject(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), action: $action);
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
	public function available(): JSONResponse {
		$result = $this->xwikiLinkService->getAvailablePages(LinkedSourceActions::search(request: $this->request));

		// Unconfigured-source / upstream-down → 503 with details.cause so
		// the picker renders its Configure-XWiki CTA (4-state UX).
		return $this->unavailableOr(result: $result, keys: ['results', 'total']);
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
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	public function search(): JSONResponse {
		$query  = $this->searchQuery();
		$limit  = (int)$this->request->getParam('limit', 25);
		$offset = (int)$this->request->getParam('offset', 0);

		$result = $this->xwikiLinkService->searchPages($query, $limit, $offset);

		// Unconfigured-source / upstream-down → 503 with details.cause so the
		// consumer renders its Configure-XWiki / unavailable state (4-state UX).
		return $this->unavailableOr(result: $result, keys: ['results', 'total', 'limit', 'offset']);
	}//end search()

	/**
	 * Link an existing remote page to the object.
	 *
	 * @param ObjectEntity $object        The object.
	 * @param string       $pageReference Full URL or `Space.Page`.
	 *
	 * @return XwikiLink The link.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function linkPage(ObjectEntity $object, string $pageReference): XwikiLink {
		return $this->xwikiLinkService->linkPage($this->linkTargetOf(object: $object), $pageReference);
	}//end linkPage()

	/**
	 * Create a remote page and link it to the object.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $space  The xWiki space.
	 * @param string       $title  The page title.
	 *
	 * @return XwikiLink The link.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function createPage(ObjectEntity $object, string $space, string $title): XwikiLink {
		return $this->xwikiLinkService->createAndLinkPage($this->linkTargetOf(object: $object), $space, $title);
	}//end createPage()

	/**
	 * The free-text query of a search: `q`, else `search`, as a string.
	 *
	 * @return string|null The query, or null when neither is given.
	 *
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	private function searchQuery(): ?string {
		$query = LinkedSourceActions::optionalString(request: $this->request, param: 'q');
		if ($query === null) {
			$query = LinkedSourceActions::optionalString(request: $this->request, param: 'search');
		}

		return $query;
	}//end searchQuery()

	/**
	 * The 503 answer when the service reports the source unavailable, else the listed keys of its result.
	 *
	 * @param array    $result The service result.
	 * @param string[] $keys   The result keys answered, in order.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	private function unavailableOr(array $result, array $keys): JSONResponse {
		if (($result['unavailable'] ?? false) === true) {
			return $this->sourceUnavailable(cause: $result['cause']);
		}

		$body = [];
		foreach ($keys as $key) {
			$body[$key] = $result[$key];
		}

		return new JSONResponse($body);
	}//end unavailableOr()

	/**
	 * The 503 answer for an unavailable xWiki source, with the cause the 4-state banner shows.
	 *
	 * @param mixed $cause The cause.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/integration-xwiki/spec.md
	 */
	private function sourceUnavailable(mixed $cause): JSONResponse {
		return LinkedSourceResponses::sourceUnavailable(error: 'xWiki source is not available', cause: $cause);
	}//end sourceUnavailable()

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
	protected function mapException(Exception $exception): JSONResponse {
		$message = $exception->getMessage();
		if ($exception->getCode() === 503 && str_starts_with($message, 'xwiki:') === true) {
			return $this->sourceUnavailable(cause: substr($message, strlen('xwiki:')));
		}

		return LinkedSourceResponses::failure(exception: $exception, passThrough: [400, 401, 404, 409, 503]);
	}//end mapException()
}//end class
