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

use OCA\OpenRegister\AppInfo\Application;
use Exception;
use OCA\OpenRegister\Controller\Trait\ChecksObjectUpdateRight;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsControllerServices;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Notifications\Service\ReplyThreadService;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Notifications\Service\EmailLinkRequest;

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
	 * @var PermissionHandlerInterface
	 */
	private readonly PermissionHandlerInterface $permissions;

	/**
	 * Resolves reply headers onto objects, when available.
	 *
	 * @var ReplyThreadService|null
	 */
	private readonly ?ReplyThreadService $replyThreads;

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request  HTTP request object
	 * @param EmailsControllerServices $services The service bundles of this API
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		EmailsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

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
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->listFor(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
	public function create(string $register, string $schema, string $id): JSONResponse {
		return $this->linkTo(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
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
	public function destroy(string $register, string $schema, string $id, string $emailId): JSONResponse {
		return $this->unlinkFrom(ref: new ObjectRef(register: $register, schema: $schema, id: $id), emailId: $emailId);
	}//end destroy()

	/**
	 * The 501 answer when the Nextcloud Mail app is not installed, else null.
	 *
	 * @return JSONResponse|null The refusal, or null when mail is available.
	 */
	private function mailUnavailable(): ?JSONResponse {
		if ($this->emailService->isMailAvailable() === false) {
			return new JSONResponse(
				['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'],
				501
			);
		}

		return null;
	}//end mailUnavailable()

	/**
	 * List the email links of the object a reference names.
	 *
	 * @param ObjectRef $ref The register, schema and object id.
	 *
	 * @return JSONResponse JSON response with email links
	 */
	private function listFor(ObjectRef $ref): JSONResponse {
		return ($this->mailUnavailable() ?? $this->listLinks(ref: $ref));
	}//end listFor()

	/**
	 * List the email links of the object a reference names, mail being available.
	 *
	 * @param ObjectRef $ref The register, schema and object id.
	 *
	 * @return JSONResponse JSON response with email links
	 */
	private function listLinks(ObjectRef $ref): JSONResponse {
		try {
			$object = $this->validateObject(ref: $ref);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			return new JSONResponse($this->linksOf(object: $object));
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end listLinks()

	/**
	 * The email links of an object, paged by the request's `limit` and `offset`.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The object.
	 *
	 * @return array The email links.
	 */
	private function linksOf(\OCA\OpenRegister\Db\ObjectEntity $object): array {
		$params = $this->request->getParams();
		$limit = ListQuery::UNBOUNDED;
		if (isset($params['limit']) === true) {
			$limit = (int)$params['limit'];
		}

		$offset = null;
		if (isset($params['offset']) === true) {
			$offset = (int)$params['offset'];
		}

		return $this->emailService->getEmailsForObject($object->getUuid(), new ListQuery(limit: $limit, offset: $offset));
	}//end linksOf()

	/**
	 * The object a reference names when the caller may change it, else the refusal.
	 *
	 * A missing object is a 404 and the update right is asked before anything
	 * is written; a DoesNotExistException propagates to the caller's 404.
	 *
	 * @param ObjectRef $ref The register, schema and object id.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|JSONResponse The object, or the 404/403 refusal.
	 */
	private function writableObject(ObjectRef $ref): \OCA\OpenRegister\Db\ObjectEntity|JSONResponse {
		$object = $this->validateObject(ref: $ref);
		if ($object === null) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		}

		if ($this->mayUpdate(object: $object) === false) {
			return new JSONResponse(['error' => 'You may not change this object, so you cannot change its emails.'], 403);
		}

		return $object;
	}//end writableObject()

	/**
	 * Run an action on the object a reference names when the caller may change it, else answer the refusal.
	 *
	 * Exceptions from the lookup or the action propagate to the caller's error mapping.
	 *
	 * @param ObjectRef $ref    The register, schema and object id.
	 * @param \Closure  $action The action on the writable object; answers the response.
	 *
	 * @return JSONResponse
	 */
	private function onWritable(ObjectRef $ref, \Closure $action): JSONResponse {
		$object = $this->writableObject(ref: $ref);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		return $action($object);
	}//end onWritable()

	/**
	 * Link the requested email to the object a reference names.
	 *
	 * @param ObjectRef $ref The register, schema and object id.
	 *
	 * @return JSONResponse JSON response with the created email link
	 */
	private function linkTo(ObjectRef $ref): JSONResponse {
		return ($this->mailUnavailable() ?? $this->linkWritable(ref: $ref));
	}//end linkTo()

	/**
	 * Link the requested email to the object a reference names, mail being available.
	 *
	 * @param ObjectRef $ref The register, schema and object id.
	 *
	 * @return JSONResponse JSON response with the created email link
	 */
	private function linkWritable(ObjectRef $ref): JSONResponse {
		try {
			return $this->onWritable(
				ref: $ref,
				action: fn (\OCA\OpenRegister\Db\ObjectEntity $object): JSONResponse => $this->linkRequested(object: $object)
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return $this->linkFailure(exception: $e);
		}//end try
	}//end linkWritable()

	/**
	 * Link the email the request names to an object the caller may change.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The object.
	 *
	 * @return JSONResponse The created link (201), or 400 when the request names no email.
	 *
	 * @throws Exception When the account is not the caller's or the link cannot be made.
	 */
	private function linkRequested(\OCA\OpenRegister\Db\ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();

		if (empty($data['mailAccountId']) === true || empty($data['mailMessageId']) === true) {
			return new JSONResponse(
				['error' => 'mailAccountId and mailMessageId are required'],
				400
			);
		}

		$this->emailLinkService->assertAccountOwnership(accountId: (int)$data['mailAccountId']);

		$link = $this->emailService->linkEmail(
			request: new EmailLinkRequest(
				objectUuid: $object->getUuid(),
				registerId: (int)$object->getRegister(),
				mailAccountId: (int)$data['mailAccountId'],
				mailMessageId: (int)$data['mailMessageId']
			)
		);

		return new JSONResponse($link, 201);
	}//end linkRequested()

	/**
	 * The answer to a failed link: 409, 403 and 404 keep their code, anything else is 400.
	 *
	 * @param Exception $exception The failure.
	 *
	 * @return JSONResponse The error response.
	 */
	private function linkFailure(Exception $exception): JSONResponse {
		$code = $exception->getCode();
		if ($code === 409) {
			return new JSONResponse(['error' => $exception->getMessage()], 409);
		}

		if ($code === 403 || $code === 404) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end linkFailure()

	/**
	 * Remove an email link from the object a reference names.
	 *
	 * @param ObjectRef $ref     The register, schema and object id.
	 * @param string                $emailId The email link ID
	 *
	 * @return JSONResponse JSON response confirming deletion
	 */
	private function unlinkFrom(ObjectRef $ref, string $emailId): JSONResponse {
		return ($this->mailUnavailable() ?? $this->unlinkWritable(ref: $ref, emailId: $emailId));
	}//end unlinkFrom()

	/**
	 * Remove an email link from the object a reference names, mail being available.
	 *
	 * @param ObjectRef $ref     The register, schema and object id.
	 * @param string    $emailId The email link ID
	 *
	 * @return JSONResponse JSON response confirming deletion
	 */
	private function unlinkWritable(ObjectRef $ref, string $emailId): JSONResponse {
		try {
			return $this->onWritable(
				ref: $ref,
				action: fn (\OCA\OpenRegister\Db\ObjectEntity $object): JSONResponse => $this->unlinkOn(object: $object, emailId: $emailId)
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			if ($e->getCode() === 404) {
				return new JSONResponse(['error' => $e->getMessage()], 404);
			}

			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
	}//end unlinkWritable()

	/**
	 * Remove one of an object's email links; an object without a uuid has none.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object  The object the caller may change.
	 * @param string                           $emailId The email link ID
	 *
	 * @return JSONResponse The confirmation, or 404 when the object has no uuid.
	 *
	 * @throws Exception When the link is not this object's.
	 */
	private function unlinkOn(\OCA\OpenRegister\Db\ObjectEntity $object, string $emailId): JSONResponse {
		$objectUuid = $object->getUuid();
		if ($objectUuid === null || $objectUuid === '') {
			return new JSONResponse(['error' => 'Email link not found'], 404);
		}

		$this->emailLinkService->unlinkEmail(objectUuid: $objectUuid, linkId: (int)$emailId);

		return new JSONResponse(['success' => true]);
	}//end unlinkOn()

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
		return ($this->mailUnavailable() ?? $this->searchSender());
	}//end search()

	/**
	 * Search the caller's mail for the request's `sender`, mail being available.
	 *
	 * @return JSONResponse JSON response with matching email links
	 */
	private function searchSender(): JSONResponse {
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
	}//end searchSender()

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
	 * @param ObjectRef $ref The register, schema and object id.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(ObjectRef $ref): ?\OCA\OpenRegister\Db\ObjectEntity {
		$this->objectService->setRegister($ref->register);
		$this->objectService->setSchema($ref->schema);
		$this->objectService->setObject($ref->id);

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
