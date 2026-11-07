<?php

/**
 * TimeTrackerLinksController — Tier-2 REST controller for NC TimeManager
 * entry links.
 *
 * Surface:
 *
 *   - GET    /api/objects/{r}/{s}/{id}/time-tracker            — list linked entries
 *   - POST   /api/objects/{r}/{s}/{id}/time-tracker            — link existing entry `{entryType,id}`
 *   - POST   /api/objects/{r}/{s}/{id}/time-tracker/new        — create + link client `{name}`
 *   - DELETE /api/objects/{r}/{s}/{id}/time-tracker/{entryId}  — unlink entry
 *   - GET    /api/integrations/time-tracker/available?search=  — client picker source
 *
 * NC TimeManager entries are user-scoped (rows carry a `user_id`), so
 * there is no admin gate — the active session's user owns the entries it
 * can link/create.
 *
 * Note: the leaf slug is `time-tracker` (with a hyphen); the underlying
 * NC app id is `timemanager` (no hyphen).
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

use Closure;
use Exception;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TimeTrackerLink;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\TimeTrackerLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 TimeManager links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class TimeTrackerLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param TimeTrackerLinkServiceInterface $linkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TimeTrackerLinkServiceInterface $linkService,
		private readonly ObjectOperationsInterface $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List linked TimeManager entries for an object.
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
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->whenTimeManagerInstalled(
			answer: fn (): JSONResponse => $this->listLinkedObject(
				route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
				action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->linkService->getLinkedEntries($object->getUuid()))
			)
		);
	}//end index()

	/**
	 * Link an existing TimeManager entry.
	 *
	 * Body: `{ entryType: 'client'|'task'|'time', id: string }`.
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
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		return $this->whenTimeManagerInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
				action: LinkedSourceActions::linkByTexts(request: $this->request, params: ['entryType', 'id'], link: $this->linkEntry(...))
			)
		);
	}//end link()

	/**
	 * Create a new TimeManager client and link it.
	 *
	 * Body: `{ name: string }`.
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
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function createAndLink(string $register, string $schema, string $id): JSONResponse {
		return $this->whenTimeManagerInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
				action: $this->namedLinkOf(create: $this->linkService->createAndLinkClient(...))
			)
		);
	}//end createAndLink()

	/**
	 * Unlink a TimeManager entry.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $entryId Upstream entry uuid.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $id matches the {id} URL route parameter; renaming breaks route binding.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(string $register, string $schema, string $id, string $entryId): JSONResponse {
		return $this->whenTimeManagerInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
				action: LinkedSourceActions::unlink(unlink: fn (string $uuid) => $this->linkService->unlink($uuid, $entryId))
			)
		);
	}//end destroy()

	/**
	 * List the current user's TimeManager clients (picker source).
	 *
	 * Query param: `search` — optional name substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: returns the current user's own TimeTracker clients; no caller-supplied object id.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function available(): JSONResponse {
		return $this->whenTimeManagerInstalled(
			answer: fn (): JSONResponse => LinkedSourceResponses::listed(
				items: $this->linkService->getAvailableClients(LinkedSourceActions::search(request: $this->request))
			)
		);
	}//end available()

	/**
	 * Link an existing TimeManager entry to the object.
	 *
	 * @param ObjectEntity $object    The object.
	 * @param string       $entryType `client`, `task` or `time`.
	 * @param string       $entryId   The upstream entry id.
	 *
	 * @return TimeTrackerLink The link.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function linkEntry(ObjectEntity $object, string $entryType, string $entryId): TimeTrackerLink {
		return $this->linkService->linkEntry($this->linkTargetOf(object: $object), $entryType, $entryId);
	}//end linkEntry()

	/**
	 * Run the answer when the TimeManager app is installed; 501 otherwise.
	 *
	 * @param Closure $answer Returns the response.
	 *
	 * @return JSONResponse The answer, or 501.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function whenTimeManagerInstalled(Closure $answer): JSONResponse {
		if ($this->linkService->isTimeManagerAvailable() === false) {
			return $this->appNotInstalled(appName: 'NC TimeManager');
		}

		return $answer();
	}//end whenTimeManagerInstalled()

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
	protected function mapException(Exception $exception): JSONResponse {
		return LinkedSourceResponses::failure(exception: $exception, passThrough: [400, 401, 404, 409, 503]);
	}//end mapException()
}//end class
