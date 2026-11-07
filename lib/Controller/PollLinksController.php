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
use Exception;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param PollLinkServiceInterface $pollLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PollLinkServiceInterface $pollLinkService,
		private readonly ObjectOperationsInterface $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		if ($this->pollLinkService->isPollsAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Polls');
		}

		return $this->listLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: $this->resultsOf(fetch: fn (ObjectEntity $object): array => $this->pollLinkService->getLinkedPolls($object->getUuid()))
		);
	}//end index()

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
		if ($this->pollLinkService->isPollsAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Polls');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$pollId = (int)$this->request->getParam('pollId', 0);
				if ($pollId === 0) {
					return new JSONResponse(['error' => 'pollId is required'], 400);
				}

				$link = $this->pollLinkService->linkPoll(
					$this->linkTargetOf(object: $object),
					$pollId
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end link()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function createNew(string $register, string $schema, string $id): JSONResponse {
		if ($this->pollLinkService->isPollsAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Polls');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$title = (string)$this->request->getParam('title', '');
				$description = (string)$this->request->getParam('description', '');
				$type = (string)$this->request->getParam('type', 'textPoll');
				$rawOptions = $this->request->getParam('options', []);
				$deadline = $this->request->getParam('deadline');

				if (trim($title) === '') {
					return new JSONResponse(['error' => 'title is required'], 400);
				}

				$options = [];
				if (is_array($rawOptions) === true) {
					foreach ($rawOptions as $option) {
						if (is_string($option) === true) {
							$options[] = $option;
						} elseif (is_array($option) === true) {
							$options[] = (string)($option['text'] ?? $option['label'] ?? '');
						}
					}
				}

				$deadlineObj = null;
				if ($deadline !== null && $deadline !== '') {
					try {
						$deadlineObj = new DateTime((string)$deadline);
					} catch (Throwable $e) {
						$deadlineObj = null;
					}
				}

				$link = $this->pollLinkService->createAndLinkPoll(
					target: $this->linkTargetOf(object: $object),
					draft: (new PollDraft(title: $title, description: $description))
						->withType(type: $type)
						->withOptions(options: $options, deadline: $deadlineObj)
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end createNew()

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
		if ($this->pollLinkService->isPollsAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Polls');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object) use ($pollId): JSONResponse {
				$this->pollLinkService->unlinkPoll($object->getUuid(), (int)$pollId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroy()

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
		if ($this->pollLinkService->isPollsAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Polls');
		}

		$search = $this->request->getParam('search');
		if ($search !== null) {
			$search = (string)$search;
		}

		$polls = $this->pollLinkService->getAvailablePolls($search);
		return new JSONResponse(['results' => $polls, 'total' => count($polls)]);
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
	 *
	 * @spec exclude Private helper: maps a service exception code to an HTTP status; the link REST contract
	 *              is owned by retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1.
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
}//end class
