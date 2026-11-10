<?php

/**
 * PollLinksController — Tier-2 REST controller for Polls poll links.
 *
 * Adds explicit link/create endpoints and an available-polls picker
 * source so the picker UX can drive its modal without leaking Polls
 * internals.
 *
 * Endpoints:
 *   - GET    /api/objects/{register}/{schema}/{id}/polls          — list
 *   - POST   /api/objects/{register}/{schema}/{id}/polls          — link existing poll
 *   - POST   /api/objects/{register}/{schema}/{id}/polls/new      — create + link
 *   - DELETE /api/objects/{register}/{schema}/{id}/polls/{pollId} — unlink
 *   - GET    /api/integrations/polls/available                    — picker source
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

use DateTime;
use Closure;
use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\PollLinksControllerServices;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\LinkedSourceActions;
use OCA\OpenRegister\Controller\Support\LinkedSourceResponses;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\PollLinkServiceInterface;
use OCA\OpenRegister\Service\PollDraft;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

/**
 * Tier-2 Poll links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class PollLinksController extends Controller {
	use ObjectLinkActionsTrait;

	/**
	 * Backing service.
	 *
	 * @var PollLinkServiceInterface
	 */
	private readonly PollLinkServiceInterface $pollLinkService;

	/**
	 * OR object resolver.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Constructor.
	 *
	 * @param IRequest                    $request  HTTP request.
	 * @param PollLinksControllerServices $services The controller's collaborators.
	 */
	public function __construct(
		IRequest $request,
		PollLinksControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->pollLinkService = $services->pollLinkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List linked polls for an object.
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
		return $this->indexForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * index() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function indexForObject(ObjectRef $ref): JSONResponse {
		return $this->whenPollsInstalled(
			answer: fn (): JSONResponse => $this->listLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->pollLinkService->getLinkedPolls($object->getUuid()))
			)
		);
	}//end indexForObject()

	/**
	 * Link an existing poll.
	 *
	 * Body: `{ pollId: int }`.
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
		return $this->linkForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end link()

	/**
	 * link() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function linkForObject(ObjectRef $ref): JSONResponse {
		return $this->whenPollsInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: (new LinkedSourceActions(request: $this->request))->linkById(
					param: 'pollId',
					link: fn (ObjectEntity $object, int $pollId) => $this->pollLinkService->linkPoll($this->linkTargetOf(object: $object), $pollId)
				)
			)
		);
	}//end linkForObject()

	/**
	 * Create a new poll and link it.
	 *
	 * Body:
	 *   `{ title, description?, type?, options: [string], deadline?: ISO-8601 }`
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
	public function createNew(string $register, string $schema, string $id): JSONResponse {
		return $this->createNewForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end createNew()

	/**
	 * createNew() for the object the route names.
	 *
	 * @param ObjectRef $ref The object the route names.
	 *
	 * @return JSONResponse
	 */
	private function createNewForObject(ObjectRef $ref): JSONResponse {
		return $this->whenPollsInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: $this->createPoll(...)
			)
		);
	}//end createNewForObject()

	/**
	 * Unlink a poll.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $pollId Polls poll id (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function destroy(string $register, string $schema, string $id, string $pollId): JSONResponse {
		return $this->destroyForObject(ref: new ObjectRef(register: $register, schema: $schema, id: $id), pollId: $pollId);
	}//end destroy()

	/**
	 * destroy() for the object the route names.
	 *
	 * @param ObjectRef $ref    The object the route names.
	 * @param string    $pollId Polls poll id (numeric).
	 *
	 * @return JSONResponse
	 */
	private function destroyForObject(ObjectRef $ref, string $pollId): JSONResponse {
		return $this->whenPollsInstalled(
			answer: fn (): JSONResponse => $this->onLinkedObject(
				route: new ObjectLinkRoute(register: (string)$ref->register, schema: (string)$ref->schema, id: $ref->id),
				action: LinkedSourceActions::unlink(unlink: fn (string $uuid) => $this->pollLinkService->unlinkPoll($uuid, (int)$pollId))
			)
		);
	}//end destroyForObject()

	/**
	 * List polls visible to the current user (picker source).
	 *
	 * Query param: `search` — optional title substring.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: returns polls visible to the current user; no caller-supplied object id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function available(): JSONResponse {
		return $this->whenPollsInstalled(
			answer: fn (): JSONResponse => LinkedSourceResponses::listed(
				items: $this->pollLinkService->getAvailablePolls(LinkedSourceActions::search(request: $this->request))
			)
		);
	}//end available()

	/**
	 * Create the poll the body describes and link it: 400 without a title, 201 with the link.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function createPoll(ObjectEntity $object): JSONResponse {
		$draft      = $this->pollDraft();
		$rawOptions = $this->request->getParam('options', []);
		$deadline   = $this->request->getParam('deadline');

		if (trim($draft->title) === '') {
			return LinkedSourceResponses::required(param: 'title');
		}

		$link = $this->pollLinkService->createAndLinkPoll(
			target: $this->linkTargetOf(object: $object),
			draft: $draft->withOptions(options: $this->pollOptions(rawOptions: $rawOptions), deadline: $this->pollDeadline(deadline: $deadline))
		);

		return LinkedSourceResponses::created(link: $link);
	}//end createPoll()

	/**
	 * The draft of the `title`, `description` and `type` params, without options yet.
	 *
	 * @return PollDraft The draft.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function pollDraft(): PollDraft {
		$title       = (string)$this->request->getParam('title', '');
		$description = (string)$this->request->getParam('description', '');
		$type        = (string)$this->request->getParam('type', 'textPoll');

		return (new PollDraft(title: $title, description: $description))->withType(type: $type);
	}//end pollDraft()

	/**
	 * The option texts of the `options` param: strings as given, `{text|label}` objects by their text.
	 *
	 * @param mixed $rawOptions The param's value.
	 *
	 * @return string[] The texts; anything else is skipped.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function pollOptions(mixed $rawOptions): array {
		if (is_array($rawOptions) === false) {
			return [];
		}

		$options = [];
		foreach ($rawOptions as $option) {
			if (is_string($option) === true) {
				$options[] = $option;
			} elseif (is_array($option) === true) {
				$options[] = (string)($option['text'] ?? $option['label'] ?? '');
			}
		}

		return $options;
	}//end pollOptions()

	/**
	 * The deadline the `deadline` param names; null when absent, empty or unparsable.
	 *
	 * @param mixed $deadline The param's value.
	 *
	 * @return DateTime|null The deadline.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function pollDeadline(mixed $deadline): ?DateTime {
		if ($deadline === null || $deadline === '') {
			return null;
		}

		try {
			return new DateTime((string)$deadline);
		} catch (Throwable $e) {
			return null;
		}
	}//end pollDeadline()

	/**
	 * Run the answer when the Polls app is installed; 501 otherwise.
	 *
	 * @param Closure $answer Returns the response.
	 *
	 * @return JSONResponse The answer, or 501.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	private function whenPollsInstalled(Closure $answer): JSONResponse {
		if ($this->pollLinkService->isPollsAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Polls');
		}

		return $answer();
	}//end whenPollsInstalled()

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
	 *
	 * @spec exclude Private helper: maps a service exception code to an HTTP status; the link REST contract
	 *              is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
	 */
	protected function mapException(Exception $exception): JSONResponse {
		return LinkedSourceResponses::failure(exception: $exception, passThrough: [409, 404, 503, 400]);
	}//end mapException()
}//end class
