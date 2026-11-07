<?php

/**
 * EmailsController
 *
 * REST controller for email relation operations on OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 * @spec openspec/specs/mail-sidebar/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use Exception;
use OCA\OpenRegister\Controller\Trait\ChecksObjectUpdateRight;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsControllerServices;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\ReplyThreadService;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * EmailsController handles email relation operations for objects in registers.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class EmailsController extends Controller {
	use ChecksObjectUpdateRight;


	/**
	 * Email service.
	 *
	 * @var EmailService
	 */
	private readonly EmailService $emailService;

	/**
	 * Object service for object validation.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * User session.
	 *
	 * @var \OCP\IUserSession
	 */
	private readonly \OCP\IUserSession $userSession;

	/**
	 * Logger.
	 *
	 * @var \Psr\Log\LoggerInterface
	 */
	private readonly \Psr\Log\LoggerInterface $logger;

	/**
	 * Object-scoped email link reads and writes.
	 *
	 * @var EmailLinkServiceInterface
	 */
	private readonly EmailLinkServiceInterface $emailLinkService;

	/**
	 * Answers the object update right.
	 *
	 * @var PermissionHandler
	 */
	private readonly PermissionHandler $permissions;

	/**
	 * Resolves reply headers onto objects, when available.
	 *
	 * @var ReplyThreadService|null
	 */
	private readonly ?ReplyThreadService $replyThreads;

	/**
	 * Constructor.
	 *
	 * @param string                   $appName  Application name
	 * @param IRequest                 $request  HTTP request object
	 * @param EmailsControllerServices $services The service bundles of this API
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		EmailsControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->emailService = $services->domain->emailService;
		$this->emailLinkService = $services->domain->emailLinkService;
		$this->objectService = $services->objectAccess->objectService;
		$this->permissions = $services->objectAccess->permissions;
		$this->userSession = $services->platform->userSession;
		$this->logger = $services->platform->logger;
		$this->replyThreads = $services->replyThreads;
	}//end __construct()

	/**
	 * List all email links for a specific object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 *
	 * @return JSONResponse JSON response with email links
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function index(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		if ($this->emailService->isMailAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$params = $this->request->getParams();
			$limit = null;
			if (isset($params['limit']) === true) {
				$limit = (int)$params['limit'];
			}

			$offset = null;
			if (isset($params['offset']) === true) {
				$offset = (int)$params['offset'];
			}

			$result = $this->emailService->getEmailsForObject($object->getUuid(), $limit, $offset);

			return new JSONResponse($result);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end index()

	/**
	 * Link an email to a specific object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 *
	 * @return JSONResponse JSON response with the created email link
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function create(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		if ($this->emailService->isMailAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			if ($this->mayUpdate(object: $object) === false) {
				return new JSONResponse(['error' => 'You may not change this object, so you cannot change its emails.'], 403);
			}

			$data = $this->request->getParams();

			if (empty($data['mailAccountId']) === true || empty($data['mailMessageId']) === true) {
				return new JSONResponse(
					['error' => 'mailAccountId and mailMessageId are required'],
					400
				);
			}

			$this->emailLinkService->assertAccountOwnership(accountId: (int)$data['mailAccountId']);

			$link = $this->emailService->linkEmail(
				$object->getUuid(),
				(int)$object->getRegister(),
				(int)$data['mailAccountId'],
				(int)$data['mailMessageId']
			);

			return new JSONResponse($link, 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			$code = $e->getCode();
			if ($code === 409) {
				return new JSONResponse(['error' => $e->getMessage()], 409);
			}

			if ($code === 403 || $code === 404) {
				return new JSONResponse(['error' => $e->getMessage()], $code);
			}

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
	}//end create()

	/**
	 * Remove an email link from an object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 * @param string $emailId The email link ID
	 *
	 * @return JSONResponse JSON response confirming deletion
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function destroy(
		string $register,
		string $schema,
		string $id,
		string $emailId,
	): JSONResponse {
		if ($this->emailService->isMailAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			if ($this->mayUpdate(object: $object) === false) {
				return new JSONResponse(['error' => 'You may not change this object, so you cannot change its emails.'], 403);
			}

			$objectUuid = $object->getUuid();
			if ($objectUuid === null || $objectUuid === '') {
				return new JSONResponse(['error' => 'Email link not found'], 404);
			}

			$this->emailLinkService->unlinkEmail(objectUuid: $objectUuid, linkId: (int)$emailId);

			return new JSONResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			$code = $e->getCode();
			if ($code === 404) {
				return new JSONResponse(['error' => $e->getMessage()], 404);
			}

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
	}//end destroy()

	/**
	 * Search email links by sender.
	 *
	 * @return JSONResponse JSON response with matching email links
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Guarded downstream: EmailService::searchBySender scopes the
	 *   mailbox scan to the caller's own Mail accounts (oc_mail_accounts.user_id = session
	 *   UID) and returns [] for an anonymous session, so a caller only ever sees links for
	 *   mail their own accounts received.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function search(): JSONResponse {
		if ($this->emailService->isMailAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		try {
			$params = $this->request->getParams();
			$sender = $params['sender'] ?? null;

			if (empty($sender) === true) {
				return new JSONResponse(['error' => 'sender parameter is required'], 400);
			}

			$results = $this->emailService->searchBySender($sender);

			return new JSONResponse(['results' => $results, 'total' => count($results)]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end search()

	/**
	 * Which object a reply threads onto, by its `In-Reply-To` and `References`.
	 *
	 * Answers `threaded`, `ambiguous` or `unthreaded`; the subject is never read.
	 *
	 * @return JSONResponse The answer.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Guarded downstream: ReplyThreadService keeps only objects the
	 *   caller can read through ObjectService::find() with RBAC and multitenancy on.
	 *
	 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
	 */
	public function resolve(): JSONResponse {
		if ($this->replyThreads === null) {
			return new JSONResponse(['error' => 'Reply threading is not available'], 501);
		}

		return new JSONResponse(
			$this->replyThreads->resolve(
				headers: [
					'In-Reply-To' => $this->request->getParam('inReplyTo', ''),
					'References' => $this->request->getParam('references', ''),
				]
			)
		);
	}//end resolve()

	/**
	 * Validate that the object exists and return it.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(
		string $register,
		string $schema,
		string $id,
	): ?\OCA\OpenRegister\Db\ObjectEntity {
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()

	/**
	 * Find objects linked to emails from a specific sender.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Guarded downstream: EmailService::searchBySender scopes the
	 *   mailbox scan to the caller's own Mail accounts (oc_mail_accounts.user_id = session
	 *   UID) and returns [] for an anonymous session.
	 *
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	public function bySender(): JSONResponse {
		$sender = $this->request->getParam('sender');

		if (empty($sender) === true) {
			return new JSONResponse(['error' => 'The sender parameter is required'], 400);
		}

		try {
			$result = $this->emailService->searchBySender($sender);
			return new JSONResponse($result);
		} catch (\Exception $e) {
			$this->logger->error('Failed to find objects by sender: {error}', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Internal server error'], 500);
		}
	}//end bySender()
}//end class
