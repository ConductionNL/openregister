<?php

/**
 * EmailLinksController — Tier-2 REST controller for Mail message links.
 *
 * Augments the legacy {@see EmailsController} with explicit picker
 * routes (accounts, mailboxes, messages) and an idempotent link/unlink
 * surface so the multi-step modal in `CnEmailPicker` can drive the
 * full flow without leaking Mail internals.
 *
 * Endpoints:
 *   - GET    /api/objects/{register}/{schema}/{id}/emails               — list (paginated)
 *   - POST   /api/objects/{register}/{schema}/{id}/emails               — link
 *   - DELETE /api/objects/{register}/{schema}/{id}/emails/{linkId}      — unlink
 *   - GET    /api/integrations/email/accounts                           — picker step 1
 *   - GET    /api/integrations/email/accounts/{accountId}/mailboxes     — step 2
 *   - GET    /api/integrations/email/accounts/{accountId}/messages      — step 3 (?mailbox=…)
 *
 * The Tier-1 `EmailsController` is kept intact for now to honour the
 * existing `_mail`/search-by-sender contract; the routes file maps the
 * Tier-2 picker URLs onto this controller while leaving the legacy
 * routes untouched.
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
use OCA\OpenRegister\Controller\Trait\ChecksObjectUpdateRight;
use OCA\OpenRegister\Controller\Trait\ObjectLinkActionsTrait;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tier-2 Email links controller.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class EmailLinksController extends Controller {
	use ObjectLinkActionsTrait;
	use ChecksObjectUpdateRight;

	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param EmailLinkServiceInterface $emailLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService OR object resolver.
	 * @param PermissionHandler $permissions Answers the object update right
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EmailLinkServiceInterface $emailLinkService,
		private readonly ObjectOperationsInterface $objectService,
		private readonly PermissionHandler $permissions,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List linked Mail messages for an object — paginated.
	 *
	 * Query string: `cursor` (numeric link-id-derived offset, optional)
	 *               and `limit` (clamped server-side).
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
		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				$cursorStr = $this->requestedCursor();
				$limit     = (int)$this->request->getParam('limit', 50);

				$result = $this->emailLinkService->getLinkedEmails(
					$object->getUuid(),
					$cursorStr,
					$limit
				);

				return new JSONResponse($result);
			}
		);
	}//end index()

	/**
	 * Link a Mail message to an object.
	 *
	 * Body: `{ mailAccountId, messageId, messageUid }`.
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
		if ($this->emailLinkService->isMailAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Mail');
		}

		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object): JSONResponse {
				if ($this->mayUpdate(object: $object) === false) {
					return $this->updateRefused();
				}

				$mailAccountId = (int)$this->request->getParam('mailAccountId', 0);
				$messageId = (string)$this->request->getParam('messageId', '');
				$messageUid = (string)$this->request->getParam('messageUid', '');

				if ($mailAccountId === 0 || $messageId === '') {
					return new JSONResponse(
						['error' => 'mailAccountId and messageId are required'],
						400
					);
				}

				$link = $this->emailLinkService->linkEmail(
					target: $this->linkTargetOf(object: $object),
					message: (new EmailMessageRef(mailAccountId: $mailAccountId, messageId: $messageId))
						->withUid(uid: $messageUid)
				);

				return new JSONResponse($link->jsonSerialize(), 201);
			}
		);
	}//end link()

	/**
	 * Unlink a Mail message from an object.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema Schema slug or id.
	 * @param string $id Object id.
	 * @param string $linkId Link primary key (numeric).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function destroy(string $register, string $schema, string $id, string $linkId): JSONResponse {
		return $this->onLinkedObject(
			route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id),
			action: function (ObjectEntity $object) use ($linkId): JSONResponse {
				if ($this->mayUpdate(object: $object) === false) {
					return $this->updateRefused();
				}

				$this->emailLinkService->unlinkEmail($object->getUuid(), (int)$linkId);

				return new JSONResponse(['success' => true]);
			}
		);
	}//end destroy()

	/**
	 * The answer to a caller who may read the object but not change it.
	 *
	 * @return JSONResponse The 403.
	 */
	private function updateRefused(): JSONResponse {
		return new JSONResponse(['error' => 'You may not change this object, so you cannot change its emails.'], 403);
	}//end updateRefused()

	/**
	 * List Mail accounts owned by the current user (picker step 1).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: EmailLinkService::getAvailableAccounts filters oc_mail_accounts by user_id = current UID;
	 *   no caller-supplied object id.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function accounts(): JSONResponse {
		if ($this->emailLinkService->isMailAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Mail');
		}

		$accounts = $this->emailLinkService->getAvailableAccounts();
		return new JSONResponse(['results' => $accounts, 'total' => count($accounts)]);
	}//end accounts()

	/**
	 * List mailboxes for a Mail account (picker step 2).
	 *
	 * @param string $accountId Mail account id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function mailboxes(string $accountId): JSONResponse {
		if ($this->emailLinkService->isMailAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Mail');
		}

		try {
			$mailboxes = $this->emailLinkService->getMailboxesForAccount((int)$accountId);
			return new JSONResponse(['results' => $mailboxes, 'total' => count($mailboxes)]);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}
	}//end mailboxes()

	/**
	 * List messages in a mailbox (picker step 3).
	 *
	 * Query string: `mailbox` (required), `cursor` (optional), `limit` (optional).
	 *
	 * @param string $accountId Mail account id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-1
	 */
	public function messages(string $accountId): JSONResponse {
		if ($this->emailLinkService->isMailAvailable() === false) {
			return $this->appNotInstalled(appName: 'Nextcloud Mail');
		}

		try {
			$mailbox = (string)$this->request->getParam('mailbox', '');
			if ($mailbox === '') {
				return new JSONResponse(['error' => 'mailbox query parameter is required'], 400);
			}

			$cursorStr = $this->requestedCursor();
			$limit     = (int)$this->request->getParam('limit', 50);

			$result = $this->emailLinkService->getMessagesForMailbox(
				(int)$accountId,
				$mailbox,
				$cursorStr,
				$limit
			);

			return new JSONResponse($result);
		} catch (Exception $e) {
			return $this->mapException(exception: $e);
		}//end try
	}//end messages()

	/**
	 * The paging cursor of the request, null when absent.
	 *
	 * @return string|null The cursor.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	private function requestedCursor(): ?string {
		$cursor = $this->request->getParam('cursor');
		if ($cursor === null) {
			return null;
		}

		return (string)$cursor;
	}//end requestedCursor()

	/**
	 * Map a service-layer Exception to a JSONResponse.
	 *
	 * Exception codes carry HTTP intent:
	 *   - 401 → unauthorized
	 *   - 403 → forbidden
	 *   - 404 → not found
	 *   - 409 → conflict
	 *   - 503 → service unavailable
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
		if (in_array($code, [401, 403, 404, 409, 503], true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end mapException()
}//end class
