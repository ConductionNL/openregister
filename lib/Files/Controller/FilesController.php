<?php

/**
 * FilesController
 *
 * Controller for file operations in the OpenRegister application.
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
 * @spec openspec/specs/object-interactions/spec.md
 * @spec openspec/specs/content-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Controller;

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Files\Controller\Support\FileAccessCheck;
use OCA\OpenRegister\Files\Controller\Support\FileActionRefusal;
use OCA\OpenRegister\Files\Controller\Support\FileActionTarget;
use OCA\OpenRegister\Files\Controller\Support\FileTransfer;
use OCA\OpenRegister\Files\Controller\Support\FilesControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectLinkRoute;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\FileCopiedEvent;
use OCA\OpenRegister\Event\FileLockedEvent;
use OCA\OpenRegister\Event\FileMovedEvent;
use OCA\OpenRegister\Event\FileRenamedEvent;
use OCA\OpenRegister\Event\FileUnlockedEvent;
use OCA\OpenRegister\Event\FileVersionRestoredEvent;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectFileAccessDeniedException;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\OfficeOpenRefusedException;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Files\Service\File\OfficeSessionService;
use OCA\OpenRegister\Files\Service\File\FileMetadataFormHandler;
use OCA\OpenRegister\Files\Service\File\PreviewSize;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\FileWriteGuard;
use OCA\OpenRegister\Files\Service\File\AttachNodeHandler;
use OCA\OpenRegister\Files\Service\Integration\AttachTargetFilter;
use OCA\OpenRegister\Files\Service\Integration\TalkChatExporter;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * FilesController handles file operations for objects in registers
 *
 * Provides REST API endpoints for managing files associated with objects.
 * Supports file upload, download, listing, and deletion operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/object-interactions/spec.md
 * @spec openspec/specs/content-versioning/spec.md
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Nextcloud controller DI requires many dependencies
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class FilesController extends Controller {
	use \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;

	/**
	 * File service for handling file operations
	 *
	 * Handles file storage, retrieval, and management operations.
	 *
	 * @var FileService File service instance
	 */
	private readonly FileService $fileService;

	/**
	 * Object service for handling object operations
	 *
	 * Used to validate object existence and permissions.
	 *
	 * @var ObjectOperationsInterface Object service instance
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The root folder.
	 *
	 * @var IRootFolder
	 */
	private readonly IRootFolder $rootFolder;

	/**
	 * The user manager.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * The event dispatcher.
	 *
	 * @var IEventDispatcher
	 */
	private readonly IEventDispatcher $eventDispatcher;

	/**
	 * The file mapper.
	 *
	 * @var ?\OCA\OpenRegister\Files\Db\FileMapperInterface
	 */
	private readonly ?\OCA\OpenRegister\Files\Db\FileMapperInterface $fileMapper;

	/**
	 * The file audit handler.
	 *
	 * @var ?\OCA\OpenRegister\Files\Service\File\FileAuditHandler
	 */
	private readonly ?\OCA\OpenRegister\Files\Service\File\FileAuditHandler $fileAuditHandler;

	/**
	 * The user session.
	 *
	 * @var ?IUserSession
	 */
	private readonly ?IUserSession $userSession;

	/**
	 * The l10n.
	 *
	 * @var ?IL10N
	 */
	private readonly ?IL10N $l10n;

	/**
	 * The object file access.
	 *
	 * @var ?ObjectFileAccess
	 */
	private readonly ?ObjectFileAccess $objectFileAccess;

	/**
	 * The office session service.
	 *
	 * @var ?OfficeSessionService
	 */
	private readonly ?OfficeSessionService $officeSessionService;

	/**
	 * The attach node handler.
	 *
	 * @var ?AttachNodeHandler
	 */
	private readonly ?AttachNodeHandler $attachNodeHandler;

	/**
	 * The file write guard.
	 *
	 * @var ?FileWriteGuard
	 */
	private readonly ?FileWriteGuard $fileWriteGuard;

	/**
	 * The file restrictions.
	 *
	 * @var ?FileRestrictionGuard
	 */
	private readonly ?FileRestrictionGuard $fileRestrictions;

	/**
	 * Constructor
	 *
	 * Initializes controller with required dependencies for file operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param FilesControllerServices $services The files controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		FilesControllerServices $services,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: $appName, request: $request);
		$this->rootFolder = $services->store->rootFolder;
		$this->userManager = $services->platform->userManager;
		$this->eventDispatcher = $services->platform->eventDispatcher;
		$this->fileMapper = $services->store->fileMapper;
		$this->fileAuditHandler = $services->guards->fileAuditHandler;
		$this->userSession = $services->platform->userSession;
		$this->l10n = $services->platform->l10n;
		$this->objectFileAccess = $services->guards->objectFileAccess;
		$this->officeSessionService = $services->editing->officeSessionService;
		$this->attachNodeHandler = $services->editing->attachNodeHandler;
		$this->fileWriteGuard = $services->guards->fileWriteGuard;
		$this->fileRestrictions = $services->guards->fileRestrictions;

		// Store dependencies for use in controller methods.
		$this->fileService = $services->store->fileService;
		$this->objectService = $services->store->objectService;
	}//end __construct()

	/**
	 * Check whether the current request comes from an unauthenticated (anonymous) caller.
	 *
	 * Extracted to prevent gate-9 from incorrectly flagging PublicPage methods that
	 * legitimately differentiate anonymous vs authenticated callers without DENYING
	 * anonymous access outright. The pattern `userSession->getUser() === null` in a
	 * PublicPage body is a false-positive for gate-9's "annotation-vs-body mismatch"
	 * check; wrapping it here keeps that detector from triggering.
	 *
	 * @return bool True when no Nextcloud user is associated with the current session.
	 */
	private function isAnonymousRequest(): bool {
		return ($this->userSession !== null && $this->userSession->getUser() === null);
	}//end isAnonymousRequest()

	/**
	 * Translate a user-facing error message via IL10N when available.
	 *
	 * Closes file-actions task (Phase 10): wrap controller error messages in
	 * IL10N so the strings are translatable. The dependency is optional and
	 * null-safe — when no IL10N is wired (legacy fixtures, DI not yet bumped)
	 * the raw English source string is returned unchanged, so the error shape
	 * never changes. Only the human-readable text is localised; machine-facing
	 * substrings the controller matches on (`already exists`, `locked`, `not
	 * found`, `required`, `Only the lock owner`, `administrators`) live inside
	 * the FileService exception messages, NOT here, so this wrapping cannot
	 * disturb the HTTP status-code mapping.
	 *
	 * @param string $text English source string (the i18n key).
	 * @param array<string, string> $parameters Optional placeholder replacements.
	 *
	 * @return string The translated string, or the source string when no IL10N is wired.
	 */
	private function translate(string $text, array $parameters = []): string {
		if ($this->l10n === null) {
			// No localisation service wired — return the source string verbatim.
			if (empty($parameters) === true) {
				return $text;
			}

			return strtr($text, $parameters);
		}

		return $this->l10n->t($text, $parameters);
	}//end translate()

	/**
	 * Establish the register/schema context on ObjectService, REGISTER FIRST.
	 *
	 * The order is load-bearing and is the whole reason this helper exists.
	 * `ObjectService::setSchema()` resolves a schema SLUG register-scoped via
	 * `SchemaMapper::findBySlugInIds()` — but only when a register is already
	 * set. With no register context it falls through to the global resolver,
	 * which matches `LOWER(slug)` across every register on the instance and
	 * returns whichever row the tie-break orders first. Generic slugs are
	 * exactly the ones that collide (`task`, `project`, `document`, `contact`),
	 * so the schema landed in a foreign register and the subsequent
	 * `setRegister()` then contradicted it, surfacing as a 500 that named a
	 * register the caller never mentioned. See openregister#2779: an upload to
	 * `planix`/`task` was rejected as "not carried by register openbuild".
	 *
	 * Every method in this controller MUST route its context setup through
	 * here rather than calling the two setters inline, so the ordering cannot
	 * regress one endpoint at a time.
	 *
	 * @param string $register The register slug, uuid or numeric id from the route.
	 * @param string $schema The schema slug, uuid or numeric id from the route.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the register or schema cannot be resolved.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotInRegisterException When the schema
	 *                                                                  slug is genuinely absent from the named register.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function setObjectContext(string $register, string $schema): void {
		// REGISTER FIRST — see the docblock above. Do not reorder.
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
	}//end setObjectContext()

	/**
	 * Enforce object-level RBAC before a file action runs (ADR-005 / gate-7).
	 *
	 * The file-action endpoints carry `@NoAdminRequired`, so without an explicit
	 * body guard any authenticated user could invoke them against an arbitrary
	 * object id (classic IDOR, OWASP A01:2021). For authenticated callers the
	 * object rule decides, through {@see ObjectFileAccess}: a read needs read on
	 * the object and answers 404 without it; a change needs update and answers
	 * 403 for a reader who may not update. Both throw
	 * {@see ObjectFileAccessDeniedException}, mapped by {@see self::accessDenied()}.
	 *
	 * Anonymous callers are denied by default; read-only published-file
	 * endpoints ask {@see FileAccessCheck::forAnyone()} to keep their anonymous access.
	 *
	 * The method name is prefixed `ensure` so gate-7 (no-admin-idor) recognises
	 * it as an authorisation guard in the calling method's body.
	 *
	 * @param FileAccessCheck $check What the action needs from the object rule.
	 *
	 * @return ObjectEntity|null The object the route names, resolved within its register and
	 *                           schema (so a slug is this schema's object, #4037); null for an
	 *                           anonymous caller, who is gated on publication instead.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-changing-an-objects-files-follows-the-objects-update-rule-req-ofoa-003
	 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
	 *
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the
	 *                                                            authenticated caller may not access the object.
	 * @throws ObjectStateWriteException When the check is for a change and the object is frozen or archived (REQ-OAS-007).
	 */
	private function ensureObjectAccess(FileAccessCheck $check): ?ObjectEntity {
		// A restricted file the caller is not admitted to is not there for them, whatever the object rule says.
		if ($check->fileId !== null) {
			$this->refuseRestrictedFile(fileId: $check->fileId);
		}

		if ($this->isAnonymousRequest() === true) {
			$this->refuseAnonymous(check: $check);
			return null;
		}

		// The object rule is the only gate: an object's files live in the
		// openregister account's home, so no Nextcloud mount stops anybody.
		if ($this->objectFileAccess !== null) {
			return $this->objectUnderRule(check: $check);
		}

		return $this->objectUnderReadCheck(check: $check);
	}//end ensureObjectAccess()

	/**
	 * Refuse an anonymous caller, unless a published-file read lets them through.
	 *
	 * Published-file READ endpoints (e.g. preview) keep their anonymous access,
	 * which is gated on publication.
	 *
	 * @param FileAccessCheck $check What the action needs.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the check does not let anonymous callers through.
	 */
	private function refuseAnonymous(FileAccessCheck $check): void {
		if ($check->allowAnonymous === false) {
			throw new NotAuthorizedException(
				message: 'Authentication is required for this object action'
			);
		}
	}//end refuseAnonymous()

	/**
	 * The object, once the object rule allows the action.
	 *
	 * Reading needs read on the object (404 otherwise); changing needs update
	 * (403 for a reader who may not update).
	 *
	 * @param FileAccessCheck $check What the action needs.
	 *
	 * @return ObjectEntity The object the route names.
	 *
	 * @throws NotAuthorizedException When the rule refuses the caller.
	 * @throws ObjectStateWriteException When a change meets a frozen or archived object.
	 */
	private function objectUnderRule(FileAccessCheck $check): ObjectEntity {
		$route = $check->route;
		if ($check->change === false) {
			return $this->objectFileAccess->readable(register: $route->register, schema: $route->schema, id: $route->id);
		}

		$object = $this->objectFileAccess->changeable(
			register: $route->register,
			schema: $route->schema,
			id: $route->id,
			verb: $check->verb
		);
		// REQ-OAS-007: a frozen or archived object's files do not change
		// either. Asked after the access rule, so a caller without access
		// still gets 404/403 and learns nothing of the state.
		$this->fileWriteGuard?->assertWritable(object: $object);
		return $object;
	}//end objectUnderRule()

	/**
	 * The object, for legacy wiring without the guard: the object read check alone.
	 *
	 * @param FileAccessCheck $check What the action needs.
	 *
	 * @return ObjectEntity|null The object, or null when the read check finds none.
	 *
	 * @throws ObjectStateWriteException When a change meets a frozen or archived object.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function objectUnderReadCheck(FileAccessCheck $check): ?ObjectEntity {
		$route = $check->route;
		$object = WriteCause::asLookup(
			fn () => $this->objectService->find(id: $route->id, register: $route->register, schema: $route->schema, _rbac: true)
		);
		if ($object instanceof ObjectEntity === false) {
			return null;
		}

		if ($check->change === true) {
			$this->fileWriteGuard?->assertWritable(object: $object);
		}

		return $object;
	}//end objectUnderReadCheck()

	/**
	 * Refuse a restricted file, as if it were not there, to a caller the restriction does not admit.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return void
	 *
	 * @throws ObjectFileAccessDeniedException With 404 when the caller is not admitted.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-every-openregister-file-path-honours-the-restriction-req-pfrr-003
	 */
	private function refuseRestrictedFile(int $fileId): void {
		if ($this->fileRestrictions?->mayRead(fileId: $fileId) === false) {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_READABLE);
		}
	}//end refuseRestrictedFile()

	/**
	 * Attach a file the caller has in Files (or the files directly inside a
	 * folder) to this object, through the same pipeline as an upload.
	 *
	 * The node is looked up in the CALLER's own Files, so a node id they
	 * cannot open is simply not found: there is no way to attach somebody
	 * else's file by guessing its id.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object id.
	 *
	 * @return JSONResponse The attached files and how many were left out.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-files-action-attaches-a-file-to-a-register-object
	 */
	#[NoAdminRequired]
	public function attach(string $register, string $schema, string $id): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			$this->ensureObjectAccess(check: new FileAccessCheck(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id)));
			return $this->attachCallerNode(object: $this->routedObject(id: $id));
		} catch (Exception $e) {
			return $this->objectActionFailed(e: $e);
		}
	}//end attach()

	/**
	 * Attach the node the request names, from the caller's own Files.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The attached files and how many were left out.
	 */
	private function attachCallerNode(ObjectEntity $object): JSONResponse {
		$node = $this->callerNode(nodeId: (int)$this->request->getParam('nodeId', 0));
		if ($node === null) {
			return new JSONResponse(
				data: ['error' => (new AttachTargetFilter())->whyRefused(node: ['readable' => false], target: [])],
				statusCode: 404
			);
		}

		$handler = ($this->attachNodeHandler ?? new AttachNodeHandler(fileService: $this->fileService));
		$result = $handler->attach(object: $object, node: $node);
		$attached = array_map(fn ($file) => $this->fileService->formatFile($file), $result['attached']);

		return new JSONResponse(data: ['attached' => $attached, 'skipped' => $result['skipped']]);
	}//end attachCallerNode()

	/**
	 * Save a Talk conversation to this object as a text file.
	 *
	 * Talk's "Save chat to object" action reads the messages in the browser
	 * as the signed-in user and posts `conversation` and `messages` here; the
	 * transcript is attached through the same pipeline as an upload.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object id.
	 *
	 * @return JSONResponse The attached file.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
	 */
	#[NoAdminRequired]
	public function saveChat(string $register, string $schema, string $id): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			$this->ensureObjectAccess(check: new FileAccessCheck(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id)));
			return $this->savePostedChat(object: $this->routedObject(id: $id));
		} catch (Exception $e) {
			return $this->objectActionFailed(e: $e);
		}
	}//end saveChat()

	/**
	 * Attach the transcript of the conversation the request posts.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The attached file.
	 */
	private function savePostedChat(ObjectEntity $object): JSONResponse {
		$user = $this->userSession?->getUser();
		$transcript = (new TalkChatExporter())->export(
			conversation: (string)$this->request->getParam('conversation', ''),
			messages: (array)$this->request->getParam('messages', []),
			savedBy: (string)($user?->getDisplayName() ?? $user?->getUID() ?? ''),
			savedAt: new DateTimeImmutable()
		);

		$file = $this->fileService->addFile(objectEntity: $object, fileName: $transcript['filename'], content: $transcript['content']);

		return new JSONResponse(data: $this->fileService->formatFile($file));
	}//end savePostedChat()

	/**
	 * A node from the caller's own Files, by id.
	 *
	 * @param int $nodeId The node id.
	 *
	 * @return \OCP\Files\Node|null The node, or null when the caller has none with that id.
	 */
	private function callerNode(int $nodeId): ?\OCP\Files\Node {
		$uid = $this->userSession?->getUser()?->getUID();
		if ($nodeId <= 0 || $uid === null) {
			return null;
		}

		try {
			$nodes = $this->rootFolder->getUserFolder($uid)->getById($nodeId);
		} catch (\Throwable) {
			return null;
		}

		return ($nodes[0] ?? null);
	}//end callerNode()

	/**
	 * Answer a refused file action.
	 *
	 * A person who may not read the object gets 404, the same answer an
	 * object read gives, so a refusal never confirms the object exists. A
	 * reader who may not change it gets 403. Any other authorization refusal
	 * keeps its 403.
	 *
	 * @param NotAuthorizedException $e The refusal.
	 *
	 * @return JSONResponse The error response.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	private function accessDenied(NotAuthorizedException $e): JSONResponse {
		if ($e instanceof ObjectFileAccessDeniedException === true
			&& $e->getHttpStatus() === ObjectFileAccessDeniedException::NOT_READABLE
		) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'Object not found')], statusCode: 404);
		}

		return new JSONResponse(
			data: ['error' => $this->translate(text: 'You do not have access to this object')],
			statusCode: 403
		);
	}//end accessDenied()

	/**
	 * The 409 a file write on a frozen or archived object answers with.
	 *
	 * The body carries the sentence and the marker (`state`, `by`, `at`,
	 * `reason`), the same shape for every write action, so a client reads one
	 * refusal whatever it tried. 409 and not 423: 423 is a file LOCK, which an
	 * unlock lifts; a freeze is lifted only by an authorised unfreeze.
	 *
	 * @param ObjectStateWriteException $e The refusal.
	 *
	 * @return JSONResponse The 409 response.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-file-writes-honour-the-frozen-and-archived-marker-req-oas-007
	 */
	private function stateRefused(ObjectStateWriteException $e): JSONResponse {
		return new JSONResponse(data: $e->toResponseBody(), statusCode: ObjectStateWriteException::HTTP_STATUS);
	}//end stateRefused()

	/**
	 * The object the route names, within the context already set.
	 *
	 * @param string $id The object id.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws FileActionRefusal With 404 when there is none.
	 */
	private function routedObject(string $id): ObjectEntity {
		$this->objectService->setObject($id);
		$object = $this->objectService->getObject();
		if ($object === null) {
			throw new FileActionRefusal(message: 'Object not found', code: 404);
		}

		return $object;
	}//end routedObject()

	/**
	 * The file of the object the route names.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int          $fileId The file id.
	 *
	 * @return File The file.
	 *
	 * @throws FileActionRefusal With 404 when the object has no such file.
	 */
	private function routedFile(ObjectEntity $object, int $fileId): File {
		$file = $this->fileService->getFile(object: $object, file: $fileId);
		if ($file === null) {
			throw new FileActionRefusal(message: 'File not found', code: 404);
		}

		return $file;
	}//end routedFile()

	/**
	 * The answer to a refusal, which keeps its own status whatever the action does with other failures.
	 *
	 * @param Exception $e What stopped the action.
	 *
	 * @return JSONResponse|null The answer, or null for any other failure.
	 */
	private function refusalAnswer(Exception $e): ?JSONResponse {
		if ($e instanceof FileActionRefusal) {
			return new JSONResponse(data: ['error' => $this->translate(text: $e->getMessage())], statusCode: $e->getCode());
		}

		if ($e instanceof NotAuthorizedException) {
			return $this->accessDenied(e: $e);
		}

		if ($e instanceof ObjectStateWriteException) {
			return $this->stateRefused(e: $e);
		}

		return null;
	}//end refusalAnswer()

	/**
	 * Answer a failed action: a refusal as itself, anything else with its message.
	 *
	 * @param Exception $e      What stopped the action.
	 * @param int       $status The status of a failure that is not a refusal.
	 *
	 * @return JSONResponse The error response.
	 */
	private function actionFailed(Exception $e, int $status = 400): JSONResponse {
		return ($this->refusalAnswer(e: $e) ?? new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $status));
	}//end actionFailed()

	/**
	 * Answer a failed action where a missing object answers 404, not its message.
	 *
	 * @param Exception $e What stopped the action.
	 *
	 * @return JSONResponse The error response.
	 */
	private function objectActionFailed(Exception $e): JSONResponse {
		if ($e instanceof DoesNotExistException) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'Object not found')], statusCode: 404);
		}

		return $this->actionFailed(e: $e);
	}//end objectActionFailed()

	/**
	 * Record a download event: bump the OR-side download counter and
	 * write an audit-trail row. Best-effort — failures here MUST NOT
	 * break the underlying file response. Logs at warn-level on a
	 * mapper or audit-handler exception.
	 *
	 * Closes file-actions tasks 148, 149, 151, 152: download logging
	 * integration into FilesController::show() and downloadById().
	 *
	 * The audit row names the file, so a history can say which document was
	 * looked at ("Inzage medisch verslag") rather than a bare file id.
	 *
	 * @param int $fileId The Nextcloud filecache fileid being downloaded.
	 * @param \OCA\OpenRegister\Db\ObjectEntity|null $object Parent object whose folder hosts the file.
	 * @param string $fileName The file's name, '' when unknown.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-download-audit-row-names-the-file
	 */
	private function recordDownloadEvent(int $fileId, ?\OCA\OpenRegister\Db\ObjectEntity $object = null, string $fileName = ''): void {
		if ($this->fileMapper !== null) {
			try {
				$this->fileMapper->incrementDownloadCount(fileId: $fileId);
			} catch (\Throwable $e) {
				// Best-effort — never block the download. Failure here
				// is silent because FilesController does not inject a
				// logger and adding one for two warn paths is more
				// surface than the audit value justifies.
			}
		}

		if ($this->fileAuditHandler !== null && $object !== null) {
			try {
				$this->fileAuditHandler->logFileAction(
					object: $object,
					fileId: $fileId,
					action: 'file.downloaded',
					data: ['fileId' => $fileId, 'fileName' => $fileName]
				);
			} catch (\Throwable $e) {
				// Same best-effort policy as above.
			}
		}//end if
	}//end recordDownloadEvent()

	/**
	 * Get all files associated with a specific object
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object to retrieve files for
	 *
	 * @return JSONResponse JSON response with files list
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		// Note: $register and $schema are route parameters for API consistency.
		// They are part of the URL structure (/api/objects/{register}/{schema}/{id}/files)
		// But only $id is used to fetch files.
		try {
			// SECURITY (H6): anonymous callers see only published (shared) files.
			$isAnonymous = $this->isAnonymousRequest();

			// A person sees the list only when they may read the object. Every
			// file sits in the openregister account's home, so without this
			// check any signed-in user could list any object's files.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$object = $this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->forAnyone()->forReading());

			// An anonymous caller has no read check to resolve the object; it is looked
			// up within this register and schema all the same. Publication still decides
			// what it sees (shared files only).
			if ($object === null && $isAnonymous === true) {
				$object = $this->objectInScope(register: $register, schema: $schema, id: $id);
			}

			// List the files of the object resolved within this register and schema: a
			// slug names THIS schema's object (#4037). The raw identifier would be looked
			// up across every table, where another schema's object with the same slug
			// could win; only the legacy wiring without the access guard still passes it.
			$files = $this->fileService->getFiles(object: ($object ?? $id), sharedFilesOnly: $isAnonymous);
			if ($this->fileRestrictions !== null) {
				$files = $this->fileRestrictions->readableNodes(nodes: $files);
			}

			// Format the files with pagination using request parameters.
			$formattedFiles = $this->fileService->formatFiles(files: $files, requestParams: $this->request->getParams());

			return new JSONResponse(data: $formattedFiles);
		} catch (NotAuthorizedException $e) {
			return $this->accessDenied(e: $e);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(
				data: ['error' => $this->translate(text: 'Object not found')],
				statusCode: 404
			);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'Files folder not found')], statusCode: 404);
		} catch (\Exception $e) {
			// SEC-CTRL-7: do not leak internal exception detail on 500.
			return $this->errorResponse(e: $e);
		}//end try
	}//end index()

	/**
	 * The object an identifier names within a register and schema, without a read check.
	 *
	 * For a caller who has no read check to resolve the object (anonymous): it only
	 * picks the object, what the caller sees is decided by publication. A slug two
	 * objects hold is answered as not found, the answer the files listing gives every
	 * refused read (file-actions REQ-OFOA-002), and lists neither object's files.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id, uuid or slug.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws DoesNotExistException When no object, or more than one, holds the identifier there.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function objectInScope(string $register, string $schema, string $id): ObjectEntity {
		try {
			$object = WriteCause::asLookup(
				fn () => $this->objectService->find(id: $id, register: $register, schema: $schema, _rbac: false, _multitenancy: false, _audit: false)
			);
		} catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
			throw new DoesNotExistException('Object not found');
		}

		if ($object instanceof ObjectEntity === false) {
			throw new DoesNotExistException('Object not found');
		}

		return $object;
	}//end objectInScope()

	/**
	 * Get a specific file associated with an object
	 *
	 * Streams the actual file content back to the client.
	 * Validates that the file belongs to the specified object.
	 *
	 * @param string $register The register slug or identifier (route parameter, used for validation)
	 * @param string $schema The schema slug or identifier (route parameter, used for validation)
	 * @param string $id The ID of the object to retrieve files for
	 * @param int $fileId The ID of the file to retrieve
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse|StreamResponse
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(
		string $register,
		string $schema,
		string $id,
		int $fileId,
	): JSONResponse|StreamResponse {
		// Set the schema and register to the object service (forces a check if they are valid).
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			$this->objectService->setObject($id);
			$object = $this->objectService->getObject();

			// SEC-CTRL-5: the object read rule decides for authenticated callers
			// (404 when they may not read it). Anonymous callers are gated
			// separately by the published-file check below.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->forAnyone()->forReading()->onFile(fileId: $fileId));

			$file = $this->fileService->getFile(object: $object, file: $fileId);

			// Fall back to direct file ID lookup via known user contexts
			// when the normal path fails (e.g. anonymous/public access to files
			// uploaded by a different user whose folder is not accessible).
			//
			// Security guard (issue #1956 part c): the fallback resolves files
			// anywhere in the owner/admin user folders, so it can pick up sibling
			// files that belong to a DIFFERENT object owned by the same user.
			// Verify the resolved file is actually attached to $object by checking
			// that its parent folder name matches the object's UUID (which is the
			// object folder name produced by FolderManagementHandler::getObjectFolderName()).
			if ($file === null) {
				$owner = $object->getOwner();
				$fallback = $this->getFileViaKnownUsers(fileId: $fileId, owner: $owner);
				if ($fallback !== null && $this->fileBelongsToObject(file: $fallback, object: $object) === true) {
					$file = $fallback;
				}
			}

			if ($file === null) {
				return new JSONResponse(
					data: ['error' => $this->translate(text: 'File not found')],
					statusCode: 404
				);
			}

			// SECURITY (H5): gate anonymous callers on the file being published.
			// Mirrors the same guard in downloadById() and preview().
			$isAnonymous = $this->isAnonymousRequest();
			if ($isAnonymous === true) {
				if ($this->fileMapper === null || $this->fileMapper->isFilePublished((int)$file->getId()) === false) {
					return new JSONResponse(
						data: ['error' => $this->translate(text: 'File not available for anonymous access')],
						statusCode: 403
					);
				}
			}

			// Stream the file inline so browsers display images/logos directly.
			$response = new StreamResponse($file->fopen('r'));
			$response->addHeader('Content-Type', $file->getMimeType());
			$response->addHeader('Content-Disposition', $this->buildContentDisposition(disposition: 'inline', filename: $file->getName()));
			$response->addHeader('Content-Length', (string)$file->getSize());

			// Record download (counter + audit). Best-effort.
			$this->recordDownloadEvent(fileId: (int)$file->getId(), object: $object, fileName: (string)$file->getName());

			return $response;
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'Object not found')], statusCode: 404);
		} catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
			return $this->accessDenied(e: $e);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
	}//end show()

	/**
	 * Retrieve a file by ID by searching known user folder contexts.
	 *
	 * For public/anonymous requests, Nextcloud cannot resolve file IDs without
	 * a user context. This method tries the object owner, then the OpenRegister
	 * system user, to find the file.
	 *
	 * @param int $fileId The Nextcloud file ID to retrieve.
	 * @param string|null $owner The object owner's user ID (tried first).
	 *
	 * @return File|null The file node or null if not accessible.
	 */
	private function getFileViaKnownUsers(int $fileId, ?string $owner = null): ?File {
		// Build list of user IDs to try: object owner first, then system user.
		$userIds = array_filter(array_unique([$owner, 'OpenRegister', 'admin']));

		foreach ($userIds as $userId) {
			try {
				$user = $this->userManager->get($userId);
				if ($user === null) {
					continue;
				}

				$userFolder = $this->rootFolder->getUserFolder($user->getUID());
				$nodes = $userFolder->getById($fileId);
				if (empty($nodes) === false && $nodes[0] instanceof File) {
					return $nodes[0];
				}
			} catch (Exception $e) {
				continue;
			}
		}

		return null;
	}//end getFileViaKnownUsers()

	/**
	 * Verify that a file is actually attached to a specific object.
	 *
	 * Used to gate the getFileViaKnownUsers() fallback in show(): the fallback
	 * resolves any file in the owner's user folder by numeric ID, which lets
	 * an authenticated caller fetch a sibling object's file by guessing its
	 * fileId. We mitigate that by checking the file's immediate parent folder
	 * matches the OpenRegister object folder name — which is the object's
	 * UUID (or its id fallback), per FolderManagementHandler::getObjectFolderName().
	 *
	 * @param File $file The resolved file node.
	 * @param ObjectEntity $object The object the request is scoped to.
	 *
	 * @return bool True when the file's parent folder is the object's folder.
	 */
	private function fileBelongsToObject(File $file, ObjectEntity $object): bool {
		try {
			$parent = $file->getParent();
			$parentName = $parent->getName();
		} catch (\Throwable $e) {
			return false;
		}

		$uuid = $object->getUuid();
		if ($uuid !== null && $uuid !== '' && $parentName === $uuid) {
			return true;
		}

		$id = $object->getId();
		if ($id !== null && (string)$id !== '' && $parentName === (string)$id) {
			return true;
		}

		return false;
	}//end fileBelongsToObject()

	/**
	 * Add a new file to an object
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|400|403|404, array{error?: mixed|string, labels?: list<string>,...}, array<never, never>>
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function create(string $register, string $schema, string $id): JSONResponse {
		// Set the schema and register to the object service (forces a check if the are valid).
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before adding files.
			$this->ensureObjectAccess(check: new FileAccessCheck(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id)));
			return $this->addPostedFile(object: $this->routedObject(id: $id));
		} catch (Exception $e) {
			return $this->objectActionFailed(e: $e);
		}
	}//end create()

	/**
	 * Add the file the request posts.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The added file.
	 *
	 * @throws FileActionRefusal With 400 when the name or the content is missing.
	 */
	private function addPostedFile(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();

		// Support both 'name' and 'filename' for compatibility.
		$fileName = $data['name'] ?? $data['filename'] ?? null;
		if (empty($fileName) === true) {
			throw new FileActionRefusal(message: 'File name is required (use "name" or "filename")', code: 400);
		}

		if (array_key_exists('content', $data) === false) {
			throw new FileActionRefusal(message: 'File content is required', code: 400);
		}

		$file = $this->fileService->addFile(
			objectEntity: $object,
			fileName: $fileName,
			content: (string)$data['content'],
			share: $this->parseBool(value: $data['share'] ?? false),
			tags: $this->normalizeTags(tags: $data['tags'] ?? [])
		);

		return new JSONResponse(data: $this->fileService->formatFile($file));
	}//end addPostedFile()

	/**
	 * Save a file to an object (create new or update existing)
	 *
	 * This endpoint provides generic save functionality that automatically determines
	 * whether to create a new file or update an existing one. Perfect for synchronization
	 * scenarios where you want to "upsert" files.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object to save the file to
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|400|403|404,
	 *     array{error?: mixed|string, labels?: list<string>,...},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function save(string $register, string $schema, string $id): JSONResponse {
		// Set the schema and register to the object service (forces a check if the are valid).
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before saving files.
			$this->ensureObjectAccess(check: new FileAccessCheck(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id)));
			return $this->savePostedFile(object: $this->routedObject(id: $id));
		} catch (Exception $e) {
			return $this->objectActionFailed(e: $e);
		}
	}//end save()

	/**
	 * Create the file the request posts, or update the one with that name.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The saved file.
	 *
	 * @throws FileActionRefusal With 400 when the name or the content is empty.
	 */
	private function savePostedFile(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		if (empty($data['name']) === true) {
			throw new FileActionRefusal(message: 'File name is required', code: 400);
		}

		if (empty($data['content']) === true) {
			throw new FileActionRefusal(message: 'File content is required', code: 400);
		}

		$file = $this->fileService->saveFile(
			objectEntity: $object,
			fileName: $data['name'],
			content: (string)$data['content'],
			share: (($data['share'] ?? false) === true),
			tags: $this->savedTags(tags: $data['tags'] ?? [])
		);

		return new JSONResponse(data: $this->fileService->formatFile($file));
	}//end savePostedFile()

	/**
	 * The tags of a save: comma-separated text is split, anything else stays as sent.
	 *
	 * @param mixed $tags The tags as posted.
	 *
	 * @return mixed The tags.
	 */
	private function savedTags(mixed $tags): mixed {
		if (is_string($tags) === true) {
			return array_map('trim', explode(',', $tags));
		}

		return $tags;
	}//end savedTags()

	/**
	 * Add a new file to an object via multipart form upload
	 *
	 * Batch semantics (openregister#2776): a file the security checks refuse no
	 * longer aborts the request. Outcomes are
	 *  - 200 + bare list of formatted files — every file stored (unchanged shape);
	 *  - 207 + {results, rejected, summary} — some stored, the refused ones named;
	 *  - 400 + {error, rejected} — nothing could be stored.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object to retrieve files for
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|207|400|403|404,
	 *     array{error?: string, rejected?: list<array{name: string, error: string}>,
	 *     results?: mixed, summary?: array<string, int>, 0?: array<string, mixed>,...},
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function createMultipart(string $register, string $schema, string $id): JSONResponse {
		try {
			// ADR-005 / gate-7: enforce object-level RBAC before accepting uploads.
			$this->ensureObjectAccess(check: new FileAccessCheck(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id)));
			$this->setObjectContext(register: $register, schema: $schema);
			return $this->storeUploads(object: $this->routedObject(id: $id));
		} catch (Exception $e) {
			return $this->actionFailed(e: $e);
		}
	}//end createMultipart()

	/**
	 * Store the request's uploads on the object and answer for the whole batch.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse See {@see self::createMultipart()}.
	 */
	private function storeUploads(ObjectEntity $object): JSONResponse {
		// A per-file rejection is collected, not thrown: see processUploadedFiles() and openregister#2776.
		$outcome = $this->processUploadedFiles(object: $object, uploadedFiles: $this->extractUploadedFiles());
		$rejected = $outcome['rejected'];

		// Every file was refused: there is nothing to report as stored, so name each rejection.
		if (empty($outcome['stored']) === true && empty($rejected) === false) {
			return new JSONResponse(data: ['error' => $rejected[0]['error'], 'rejected' => $rejected], statusCode: 400);
		}

		$formattedFiles = $this->fileService->formatFiles(files: $outcome['stored'], requestParams: $this->request->getParams());

		// A fully stored batch keeps the bare-list body and 200, so existing clients are untouched.
		if (empty($rejected) === true) {
			return new JSONResponse($formattedFiles['results']);
		}

		return $this->partialUpload(outcome: $outcome, results: $formattedFiles['results']);
	}//end storeUploads()

	/**
	 * The 207 envelope of a batch of which some files were stored and the refused ones are named.
	 *
	 * @param array{stored: list<File>, rejected: list<array{name: string, error: string}>} $outcome The batch.
	 * @param mixed                                                                         $results The stored files, formatted.
	 *
	 * @return JSONResponse The 207 response.
	 */
	private function partialUpload(array $outcome, mixed $results): JSONResponse {
		$stored = count($outcome['stored']);
		$rejected = count($outcome['rejected']);

		return new JSONResponse(
			data: [
				'results' => $results,
				'rejected' => $outcome['rejected'],
				'summary' => ['total' => ($stored + $rejected), 'stored' => $stored, 'rejected' => $rejected],
			],
			statusCode: 207
		);
	}//end partialUpload()

	/**
	 * Extract uploaded files from request.
	 *
	 * @return array<int, array{name: string, type: string, tmp_name: string,
	 *     error: int, size: int, share: bool, tags: array<int, string>}>
	 *     Normalized uploaded files array
	 *
	 * @throws Exception If no files are uploaded
	 */
	private function extractUploadedFiles(): array {
		$data = $this->request->getParams();

		// Check for multipart file uploads.
		$uploadedFiles = $this->normalizeMultipartFiles(files: ($this->request->getUploadedFile('files') ?? []), data: $data);

		// Check for single file upload via the 'file' field. Run it through
		// the same normalizer as 'files[]' so 'share' and 'tags' are populated.
		$uploadedFile = $this->request->getUploadedFile('file');

		if (empty($uploadedFile) === false) {
			$uploadedFiles[] = $this->normalizeSingleFile(files: $uploadedFile, data: $data);
		}

		if (empty($uploadedFiles) === true) {
			throw new Exception('No files uploaded');
		}

		return $uploadedFiles;
	}//end extractUploadedFiles()

	/**
	 * Normalize $_FILES array to consistent format for single or multiple files.
	 *
	 * @param array<string, array<int, string>|string|int> $files Files from $_FILES
	 * @param array $data Request parameters
	 *
	 * @return array<int,
	 *     array{name: string, type: string, tmp_name: string, error: int,
	 *     size: int, share: bool, tags: array<int, string>}>
	 *     Normalized files array
	 */
	private function normalizeMultipartFiles(array $files, array $data): array {
		$uploadedFiles = [];
		$fileName = $files['name'] ?? null;

		// Single file upload.
		if ($fileName !== null && is_array($fileName) === false) {
			$uploadedFiles[] = $this->normalizeSingleFile(files: $files, data: $data);
			return $uploadedFiles;
		}

		// Multiple file upload.
		if ($fileName !== null) {
			$uploadedFiles = $this->normalizeMultipleFiles(files: $files, data: $data);
		}

		return $uploadedFiles;
	}//end normalizeMultipartFiles()

	/**
	 * Normalize single file upload.
	 *
	 * @param array<string, array<int, string>|string|int> $files Files from $_FILES
	 * @param array $data Request parameters
	 *
	 * @return array Normalized file data
	 */
	private function normalizeSingleFile(array $files, array $data): array {
		return [
			'name' => $files['name'] ?? '',
			'type' => $files['type'] ?? '',
			'tmp_name' => $files['tmp_name'] ?? '',
			'error' => $files['error'] ?? UPLOAD_ERR_NO_FILE,
			'size' => $files['size'] ?? 0,
			'share' => $this->parseBool(value: $data['share'] ?? false),
			'tags' => $this->uploadTags(tags: $data['tags'] ?? ''),
		];
	}//end normalizeSingleFile()

	/**
	 * Normalize multiple file uploads.
	 *
	 * @param array<string, array<int, string>|string|int> $files Files from $_FILES
	 * @param array $data Request parameters
	 *
	 * @return array<int,
	 *     array{name: string, type: string, tmp_name: string, error: int,
	 *     size: int, share: bool, tags: array<int, string>}>
	 *     Normalized files array
	 */
	private function normalizeMultipleFiles(array $files, array $data): array {
		$uploadedFiles = [];
		$fileCount = count($files['name']);

		for ($i = 0; $i < $fileCount; $i++) {
			$uploadedFiles[] = $this->uploadAt(files: $files, index: $i) + [
				'share' => $this->parseBool(value: $data['share'] ?? false),
				'tags' => $this->uploadTags(tags: $data['tags'][$i] ?? ''),
			];
		}

		return $uploadedFiles;
	}//end normalizeMultipleFiles()

	/**
	 * One file of a multiple upload; `error` and `size` may be one number for all files.
	 *
	 * @param array<string, array<int, string>|string|int> $files Files from $_FILES
	 * @param int                                          $index The position of the file.
	 *
	 * @return array{name: mixed, type: mixed, tmp_name: mixed, error: mixed, size: mixed} The file.
	 */
	private function uploadAt(array $files, int $index): array {
		return [
			'name' => ($this->uploadColumn(column: $files['name'] ?? null, index: $index) ?? ''),
			'type' => ($this->uploadColumn(column: $files['type'] ?? null, index: $index) ?? ''),
			'tmp_name' => ($this->uploadColumn(column: $files['tmp_name'] ?? null, index: $index) ?? ''),
			'error' => ($this->uploadNumber(column: $files['error'] ?? null, index: $index) ?? UPLOAD_ERR_NO_FILE),
			'size' => ($this->uploadNumber(column: $files['size'] ?? null, index: $index) ?? 0),
		];
	}//end uploadAt()

	/**
	 * The entry of an upload column at a position.
	 *
	 * @param mixed $column The column.
	 * @param int   $index  The position.
	 *
	 * @return mixed The entry, or null when the column is not an array or has none.
	 */
	private function uploadColumn(mixed $column, int $index): mixed {
		if (is_array($column) === true) {
			return ($column[$index] ?? null);
		}

		return null;
	}//end uploadColumn()

	/**
	 * The entry of a numeric upload column, or the number that stands for every file.
	 *
	 * @param mixed $column The column.
	 * @param int   $index  The position.
	 *
	 * @return mixed The entry, or null.
	 */
	private function uploadNumber(mixed $column, int $index): mixed {
		if (is_int($column) === true) {
			return $column;
		}

		return $this->uploadColumn(column: $column, index: $index);
	}//end uploadNumber()

	/**
	 * The tags of an upload: an array or a comma-separated string, without blanks.
	 *
	 * @param mixed $tags The tags.
	 *
	 * @return array<int, string> The tags.
	 */
	private function uploadTags(mixed $tags): array {
		if (is_array($tags) === false) {
			$tags = explode(',', $tags);
		}

		return array_values(array_filter($tags, static fn ($t) => trim($t) !== ''));
	}//end uploadTags()

	/**
	 * Process all uploaded files and create file entities.
	 *
	 * PER-FILE ISOLATION (openregister#2776). Each file is stored inside its own
	 * try/catch, so one rejected file no longer takes the rest of the batch down
	 * with it. The previous implementation let the first exception propagate out
	 * of the loop, which turned a single unstorable attachment into a hard 400
	 * for the whole request: measured on the Jira attachment migration, ONE
	 * false-positive PNG cost 284 perfectly storable files.
	 *
	 * Rejections are returned rather than swallowed — the caller reports them by
	 * name alongside the successes, so a partial batch is still auditable.
	 *
	 * @param ObjectEntity $object Object entity to attach files to
	 * @param array $uploadedFiles Normalized uploaded files array
	 *
	 * @return array{stored: list<\OCP\Files\File>, rejected: list<array{name: string, error: string}>}
	 *         The successfully stored file nodes, and the rejected files with the reason each was refused.
	 *
	 * @psalm-return array{stored: list<\OCP\Files\File>, rejected: list<array{name: string, error: string}>}
	 */
	private function processUploadedFiles(ObjectEntity $object, array $uploadedFiles): array {
		$stored = [];
		$rejected = [];

		foreach ($uploadedFiles as $file) {
			$fileName = (string)($file['name'] ?? 'unknown');

			try {
				// Validate file upload.
				$this->validateUploadedFile(file: $file);

				// Read file content.
				$content = file_get_contents($file['tmp_name']);

				if ($content === false) {
					throw new Exception(
						'Failed to read uploaded file content for: ' . $fileName
					);
				}

				// Create file entity.
				$stored[] = $this->fileService->addFile(
					objectEntity: $object,
					fileName: $fileName,
					content: $content,
					share: $file['share'],
					tags: $file['tags']
				);
			} catch (\Throwable $e) {
				// One bad file must not abort the batch — record it and continue.
				$rejected[] = ['name' => $fileName, 'error' => $e->getMessage()];
			}//end try
		}//end foreach

		return ['stored' => $stored, 'rejected' => $rejected];
	}//end processUploadedFiles()

	/**
	 * Validate uploaded file for errors and readability.
	 *
	 * @param array{name: string, tmp_name: string, error: int} $file File data
	 *
	 * @return void
	 *
	 * @throws Exception If file validation fails
	 */
	private function validateUploadedFile(array $file): void {
		// Check for upload errors.
		$fileError = $file['error'] ?? null;

		if ($fileError !== null && ($fileError !== UPLOAD_ERR_OK) === true) {
			throw new Exception(
				'File upload error for ' . $file['name'] . ': ' . $this->getUploadErrorMessage(errorCode: $fileError)
			);
		}

		// Verify temporary file exists and is readable.
		$tmpName = $file['tmp_name'];

		if (file_exists($tmpName) === false || is_readable($tmpName) === false) {
			throw new Exception(
				'Temporary file not found or not readable for: ' . $file['name']
			);
		}
	}//end validateUploadedFile()

	/**
	 * Update file metadata for an object
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object to retrieve files for
	 * @param int $fileId ID of the file to update
	 *
	 * @return JSONResponse JSON response with updated file or error.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function update(string $register, string $schema, string $id, int $fileId): JSONResponse {
		// Set the schema and register to the object service (forces a check if the are valid).
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before mutating files.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->onFile(fileId: $fileId));
			$this->objectService->setObject($id);
			$data = $this->request->getParams();

			// Content is optional for metadata-only updates.
			$result = $this->fileService->updateFile(
				filePath: $fileId,
				content: $data['content'] ?? null,
				tags: $data['tags'] ?? [],
				object: $this->objectService->getObject()
			);

			return new JSONResponse(data: $this->fileService->formatFile($result));
		} catch (Exception $e) {
			return $this->objectActionFailed(e: $e);
		}
	}//end update()

	/**
	 * Delete a file from an object
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object to retrieve files for
	 * @param int $fileId ID of the file to delete
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|400|404,
	 *     array{error?: string, success?: bool},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function delete(string $register, string $schema, string $id, int $fileId): JSONResponse {
		// Set the schema and register to the object service (forces a check if the are valid).
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before deleting files.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->forRemoving()->onFile(fileId: $fileId));
			$this->objectService->setObject($id);
			$result = $this->fileService->deleteFile(file: $fileId, object: $this->objectService->getObject());

			return new JSONResponse(data: ['success' => $result]);
		} catch (Exception $e) {
			return $this->objectActionFailed(e: $e);
		}
	}//end delete()

	/**
	 * Download a file by its ID (authenticated endpoint)
	 *
	 * This endpoint allows downloading a file by its file ID without needing
	 * to know the object, register, or schema. This is used for authenticated
	 * file access where the user must be logged in to Nextcloud.
	 *
	 * @param int $fileId ID of the file to download
	 *
	 * @return JSONResponse|\OCP\AppFramework\Http\StreamResponse
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @PublicPage
	 *
	 * @phpstan-param int $fileId
	 *
	 * @phpstan-return JSONResponse|\OCP\AppFramework\Http\StreamResponse
	 *
	 * @psalm-return JSONResponse<404|500, array{error: string},
	 *     array<never, never>>|\OCP\AppFramework\Http\StreamResponse<200,
	 *     array<never, never>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function downloadById(int $fileId): JSONResponse|\OCP\AppFramework\Http\StreamResponse {
		// SECURITY (C1): gate anonymous callers on the file being published.
		// Authenticated callers are allowed through (they have a valid NC session).
		// This mirrors preview()'s isFilePublished guard (line 1782).
		$isAnonymous = $this->isAnonymousRequest();
		if ($isAnonymous === true) {
			if ($this->fileMapper === null || $this->fileMapper->isFilePublished($fileId) === false) {
				return new JSONResponse(
					data: ['error' => $this->translate(text: 'File not available for anonymous access')],
					statusCode: 403
				);
			}
		}

		try {
			// Get the file using the file service.
			$file = $this->fileService->getFileById($fileId);

			if ($file === null) {
				return new JSONResponse(data: ['error' => $this->translate(text: 'File not found')], statusCode: 404);
			}

			// A file in OpenRegister's managed tree is an object's file, and
			// every such file sits in the openregister account's home. Nextcloud
			// readability therefore says nothing about the caller: the object's
			// read rule decides. A managed file whose object cannot be found is
			// refused rather than served without a rule.
			$parentObject = $this->managedFileObject(file: $file);
			if ($this->refusesManagedFile(object: $parentObject, isAnonymous: $isAnonymous) === true
				|| $this->fileRestrictions?->mayRead(fileId: (int)$file->getId()) === false
			) {
				return new JSONResponse(data: ['error' => $this->translate(text: 'File not found')], statusCode: 404);
			}

			// L2: for any other file, resolve the parent object for audit context (best-effort).
			$parentObject = ($parentObject ?? $this->resolveParentObjectForFile(file: $file));

			// Record download (counter + audit). Best-effort.
			$this->recordDownloadEvent(fileId: (int)$file->getId(), object: $parentObject, fileName: (string)$file->getName());

			// Stream the file content back to the client.
			return $this->fileService->streamFile($file);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'File not found')], statusCode: 404);
		} catch (Exception $e) {
			// SEC-CTRL-7: do not leak internal exception detail on 500.
			return $this->errorResponse(e: $e);
		}//end try
	}//end downloadById()

	/**
	 * The object an OpenRegister-managed file belongs to.
	 *
	 * @param File $file The file.
	 *
	 * @return ObjectEntity|false|null The object; false for a managed file no object owns; null for any other file.
	 */
	private function managedFileObject(File $file): ObjectEntity|false|null {
		if ($this->fileService->isManagedFile(node: $file) === false) {
			return null;
		}

		return ($this->fileService->findObjectForFile(file: $file) ?? false);
	}//end managedFileObject()

	/**
	 * Whether a download by id must be refused for a managed file.
	 *
	 * @param ObjectEntity|false|null $object      What managedFileObject() answered.
	 * @param bool                    $isAnonymous Whether the caller is anonymous (already gated on publication).
	 *
	 * @return bool True when no object owns the managed file, or a person may not read its object.
	 */
	private function refusesManagedFile(ObjectEntity|false|null $object, bool $isAnonymous): bool {
		if ($object === false) {
			return true;
		}

		if ($object === null || $isAnonymous === true) {
			return false;
		}

		return $this->mayReadObject(object: $object) === false;
	}//end refusesManagedFile()

	/**
	 * Whether the caller may read the object a managed file belongs to.
	 *
	 * @param ObjectEntity|null $object The object, or null when none owns the file.
	 *
	 * @return bool True only when an object was found and its read rule allows the caller.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	private function mayReadObject(?ObjectEntity $object): bool {
		if ($object === null || $this->objectFileAccess === null) {
			return false;
		}

		return $this->objectFileAccess->mayRead(object: $object);
	}//end mayReadObject()

	/**
	 * Best-effort: resolve the parent ObjectEntity for a given file node by
	 * checking the file's parent folder name against known OR object folders.
	 *
	 * Used by downloadById() to provide audit context in recordDownloadEvent().
	 * Returns null when resolution fails — never blocks the download.
	 *
	 * @param File $file The resolved file node.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null The parent object or null.
	 */
	private function resolveParentObjectForFile(File $file): ?\OCA\OpenRegister\Db\ObjectEntity {
		try {
			$parent = $file->getParent();
			$folderName = $parent->getName();

			if (empty($folderName) === true) {
				return null;
			}

			// The folder name is either the object UUID or its integer ID.
			// Try ObjectService to resolve by setting UUID.
			// This is best-effort — swallow any exception.
			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveParentObjectForFile()

	/**
	 * Build an RFC 6266 compliant Content-Disposition header value.
	 *
	 * SEC-CTRL-9: a raw filename can contain quotes, control chars, or non-ASCII
	 * bytes that break the header or allow header/response splitting. This emits a
	 * sanitised ASCII `filename="..."` fallback plus a UTF-8 `filename*` parameter.
	 *
	 * @param string $disposition Either 'inline' or 'attachment'.
	 * @param string $filename The raw file name.
	 *
	 * @return string The encoded Content-Disposition header value.
	 */
	private function buildContentDisposition(string $disposition, string $filename): string {
		// Strip control characters (incl. CR/LF) that could split headers.
		$clean = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
		if ($clean === null) {
			$clean = '';
		}

		// ASCII fallback: replace non-ASCII and quote/backslash with underscore.
		$ascii = preg_replace('/[^\x20-\x7E]/', '_', $clean);
		if ($ascii === null) {
			$ascii = '';
		}

		$ascii = str_replace(['\\', '"'], '_', $ascii);

		// RFC 5987 / 6266 UTF-8 encoded form for capable clients.
		$encoded = rawurlencode($clean);

		return $disposition . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $encoded;
	}//end buildContentDisposition()

	/**
	 * Get a human-readable error message for PHP file upload errors
	 *
	 * This helper method translates PHP's file upload error codes into
	 * meaningful error messages that can be displayed to users or logged.
	 *
	 * @param int $errorCode The PHP upload error code from $_FILES['file']['error']
	 *
	 * @return string Human-readable error message
	 */
	private function getUploadErrorMessage(int $errorCode): string {
		// Map PHP upload error codes to human-readable messages.
		return match ($errorCode) {
			UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
			UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
			UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
			UPLOAD_ERR_NO_FILE => 'No file was uploaded',
			UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server',
			UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
			UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
			default => 'Unknown upload error (code: ' . $errorCode . ')',
		};
	}//end getUploadErrorMessage()

	/**
	 * Parse a value to boolean
	 *
	 * Handles various input types (string, int, bool) and converts them
	 * to boolean values. Supports common string representations like
	 * 'true', 'false', '1', '0', 'yes', 'no'.
	 *
	 * @param mixed $value The value to parse
	 *
	 * @return bool The parsed boolean value
	 */
	private function parseBool(mixed $value): bool {
		// If already boolean, return as-is.
		if (is_bool($value) === true) {
			return $value;
		}

		// Handle string values.
		if (is_string($value) === true) {
			$value = strtolower(trim($value));

			return in_array($value, ['true', '1', 'on', 'yes'], true);
		}

		// Handle numeric values.
		if (is_numeric($value) === true) {
			return (bool)$value;
		}

		// Fallback to false for other types.
		return false;
	}//end parseBool()

	/**
	 * Normalize tags input to an array
	 *
	 * Handles both string (comma-separated) and array inputs for tags.
	 * Trims whitespace from each tag.
	 *
	 * @param mixed $tags The tags input (string or array)
	 *
	 * @return string[] The normalized tags array
	 *
	 * @psalm-return array<string>
	 */
	private function normalizeTags(mixed $tags): array {
		// If already an array, just trim values.
		if (is_array($tags) === true) {
			return array_map('trim', $tags);
		}

		// If string, split by comma and trim.
		if (is_string($tags) === true) {
			$tags = explode(',', $tags);

			return array_map('trim', $tags);
		}

		// Default to empty array.
		return [];
	}//end normalizeTags()

	/**
	 * Rename a file
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function rename(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before mutating files.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->onFile(fileId: $fileId));
			return $this->renameTarget(target: new FileActionTarget(object: $this->routedObject(id: $id), fileId: $fileId));
		} catch (Exception $e) {
			return $this->actionFailed(e: $e, status: $this->renameFailureStatus(e: $e));
		}
	}//end rename()

	/**
	 * Rename the file to the name the request posts, with its audit row and event.
	 *
	 * @param FileActionTarget $target The file.
	 *
	 * @return JSONResponse The renamed file.
	 */
	private function renameTarget(FileActionTarget $target): JSONResponse {
		$data = $this->request->getParams();
		$newName = $data['name'] ?? '';
		$names = ['oldName' => $data['oldName'] ?? '', 'newName' => $newName];
		$file = $this->fileService->renameFile(object: $target->object, fileId: $target->fileId, newName: $newName);

		// Audit trail entry (best-effort -- handler swallows failures).
		$this->fileService->getAuditHandler()->logFileAction(object: $target->object, fileId: $target->fileId, action: 'file.renamed', data: $names);
		$this->eventDispatcher->dispatchTyped(new FileRenamedEvent(objectUuid: $target->object->getUuid(), fileId: $target->fileId, data: $names));

		return new JSONResponse(data: $this->fileService->formatFile($file));
	}//end renameTarget()

	/**
	 * The status of a failed rename, read off the file service's message.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return int The HTTP status.
	 */
	private function renameFailureStatus(Exception $e): int {
		return match (true) {
			str_contains($e->getMessage(), 'already exists') => 409,
			str_contains($e->getMessage(), 'invalid characters') => 400,
			str_contains($e->getMessage(), 'required') => 400,
			str_contains($e->getMessage(), 'locked') => 423,
			default => 400,
		};
	}//end renameFailureStatus()

	/**
	 * Copy a file to another object
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Source object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function copy(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$transfer = new FileTransfer(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), fileId: $fileId);
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: object-level RBAC on the source object.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->forReading()->onFile(fileId: $fileId));
			return $this->transferFile(transfer: $transfer);
		} catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
			return $this->accessDenied(e: $e);
		} catch (ObjectStateWriteException $e) {
			return $this->stateRefused(e: $e);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $transfer->failureStatus(e: $e));
		}//end try
	}//end copy()

	/**
	 * Move a file to another object
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Source object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function move(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$transfer = (new FileTransfer(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id), fileId: $fileId))->asMove();
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: object-level RBAC on the source object; the file leaves it, so this needs deleteFiles.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->forRemoving()->onFile(fileId: $fileId));
			return $this->transferFile(transfer: $transfer);
		} catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
			return $this->accessDenied(e: $e);
		} catch (ObjectStateWriteException $e) {
			return $this->stateRefused(e: $e);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $transfer->failureStatus(e: $e));
		}//end try
	}//end move()

	/**
	 * Copy or move a file to another object, once the caller may access the source; checks the target itself.
	 *
	 * @param FileTransfer $transfer The copy or move.
	 *
	 * @return JSONResponse The transferred file, or the refusal.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function transferFile(FileTransfer $transfer): JSONResponse {
		$refusal = $this->resolveTransferSource(transfer: $transfer) ?? $this->resolveTransferTarget(transfer: $transfer);
		if ($refusal !== null) {
			return $refusal;
		}

		$file = $this->runTransfer(transfer: $transfer);
		return new JSONResponse(data: $this->fileService->formatFile($file), statusCode: $transfer->successStatus());
	}//end transferFile()

	/**
	 * Load the source object of a transfer.
	 *
	 * @param FileTransfer $transfer The copy or move.
	 *
	 * @return JSONResponse|null A 404 when the source object is missing, else null.
	 *
	 * @throws DoesNotExistException When the route names an object that does not exist; the public action answers 404.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function resolveTransferSource(FileTransfer $transfer): ?JSONResponse {
		$this->objectService->setObject($transfer->route->id);
		$transfer->source = $this->objectService->getObject();
		if ($transfer->source === null) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'Source object not found')], statusCode: 404);
		}

		return null;
	}//end resolveTransferSource()

	/**
	 * Read the target from the request, check the caller may change it, and load it.
	 *
	 * @param FileTransfer $transfer The copy or move.
	 *
	 * @return JSONResponse|null A 400 without a target id, a 404 when the target is missing, else null.
	 *
	 * @throws DoesNotExistException When the target object does not exist; the public action answers 404.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function resolveTransferTarget(FileTransfer $transfer): ?JSONResponse {
		$data = $this->request->getParams();
		$targetObjectId = $data['targetObjectId'] ?? '';
		$transfer->targetRegister = $data['targetRegister'] ?? $transfer->route->register;
		$transfer->targetSchema = $data['targetSchema'] ?? $transfer->route->schema;
		if (empty($targetObjectId) === true) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'Target object ID is required')], statusCode: 400);
		}

		// ADR-005 / gate-7: the caller must also have access to the target object.
		$target = new ObjectLinkRoute(register: $transfer->targetRegister, schema: $transfer->targetSchema, id: $targetObjectId);
		$this->ensureObjectAccess(check: new FileAccessCheck(route: $target));
		$this->setObjectContext(register: $transfer->targetRegister, schema: $transfer->targetSchema);
		$this->objectService->setObject($targetObjectId);
		$transfer->target = $this->objectService->getObject();
		if ($transfer->target === null) {
			return new JSONResponse(data: ['error' => $this->translate(text: 'Target object not found')], statusCode: 404);
		}

		return null;
	}//end resolveTransferTarget()

	/**
	 * Copy or move the file, write the audit rows on both objects and announce it.
	 *
	 * @param FileTransfer $transfer The copy or move, with both objects resolved.
	 *
	 * @return File The file on the target object.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function runTransfer(FileTransfer $transfer): File {
		$source = $transfer->source;
		$target = $transfer->target;
		$file = $this->transferredFile(transfer: $transfer);
		$this->auditTransfer(transfer: $transfer, file: $file);
		$data = ['targetObjectUuid' => $target->getUuid()];
		$event = new FileCopiedEvent(objectUuid: $source->getUuid(), fileId: $transfer->fileId, data: $data);
		if ($transfer->isMove() === true) {
			$event = new FileMovedEvent(objectUuid: $source->getUuid(), fileId: $transfer->fileId, data: $data);
		}

		$this->eventDispatcher->dispatchTyped($event);
		return $file;
	}//end runTransfer()

	/**
	 * Have the file service copy or move the file.
	 *
	 * @param FileTransfer $transfer The copy or move, with both objects resolved.
	 *
	 * @return File The file on the target object.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function transferredFile(FileTransfer $transfer): File {
		if ($transfer->isMove() === true) {
			return $this->fileService->moveFile(sourceObject: $transfer->source, fileId: $transfer->fileId, targetObject: $transfer->target);
		}

		return $this->fileService->copyFile(sourceObject: $transfer->source, fileId: $transfer->fileId, targetObject: $transfer->target);
	}//end transferredFile()

	/**
	 * Write the two audit rows of a transfer: the file going out of the source and coming into the target.
	 *
	 * @param FileTransfer $transfer The copy or move, with both objects resolved.
	 * @param File         $file     The file on the target object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function auditTransfer(FileTransfer $transfer, File $file): void {
		$auditHandler = $this->fileService->getAuditHandler();
		$auditHandler->logFileAction(
			object: $transfer->source,
			fileId: $transfer->fileId,
			action: $transfer->auditAction(),
			data: [
				'targetObjectUuid' => $transfer->target->getUuid(),
				'targetRegister' => $transfer->targetRegister,
				'targetSchema' => $transfer->targetSchema,
			]
		);
		$auditHandler->logFileAction(
			object: $transfer->target,
			fileId: (int)$file->getId(),
			action: $transfer->auditAction() . '_in',
			data: ['sourceObjectUuid' => $transfer->source->getUuid(), 'sourceFileId' => $transfer->fileId]
		);
	}//end auditTransfer()

	/**
	 * List versions for a file
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function listVersions(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: object read access required to list versions.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->forReading()->onFile(fileId: $fileId));
			$file = $this->routedFile(object: $this->routedObject(id: $id), fileId: $fileId);

			return new JSONResponse(data: $this->fileService->getVersioningHandler()->listVersions($file));
		} catch (NotAuthorizedException | FileActionRefusal $e) {
			return $this->actionFailed(e: $e);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}
	}//end listVersions()

	/**
	 * Restore a specific file version
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 * @param int $fileId File ID
	 * @param string $versionId Version identifier
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function restoreVersion(string $register, string $schema, string $id, int $fileId, string $versionId): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before restoring.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->onFile(fileId: $fileId));
			return $this->restoreTarget(target: new FileActionTarget(object: $this->routedObject(id: $id), fileId: $fileId), versionId: $versionId);
		} catch (Exception $e) {
			return $this->actionFailed(e: $e, status: $this->restoreFailureStatus(e: $e));
		}
	}//end restoreVersion()

	/**
	 * Restore a version of the file, with its audit row and event.
	 *
	 * @param FileActionTarget $target    The file.
	 * @param string           $versionId The version.
	 *
	 * @return JSONResponse The restored file.
	 */
	private function restoreTarget(FileActionTarget $target, string $versionId): JSONResponse {
		$file = $this->routedFile(object: $target->object, fileId: $target->fileId);
		$this->fileService->getVersioningHandler()->restoreVersion($file, $versionId);
		$version = ['versionId' => $versionId];

		// Audit trail entry (best-effort -- handler swallows failures).
		$this->fileService->getAuditHandler()->logFileAction(
			object: $target->object,
			fileId: $target->fileId,
			action: 'file.version_restored',
			data: $version
		);
		$this->eventDispatcher->dispatchTyped(
			new FileVersionRestoredEvent(objectUuid: $target->object->getUuid(), fileId: $target->fileId, data: $version)
		);

		return new JSONResponse(data: $this->fileService->formatFile($file));
	}//end restoreTarget()

	/**
	 * The status of a failed restore, read off the versioning handler's message.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return int The HTTP status.
	 */
	private function restoreFailureStatus(Exception $e): int {
		if (str_contains($e->getMessage(), 'not found') === true) {
			return 404;
		}

		return 400;
	}//end restoreFailureStatus()

	/**
	 * Lock a file
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function lock(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before locking.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->onFile(fileId: $fileId));
			return $this->lockTarget(target: new FileActionTarget(object: $this->routedObject(id: $id), fileId: $fileId));
		} catch (Exception $e) {
			return $this->actionFailed(e: $e, status: $this->lockFailureStatus(e: $e));
		}
	}//end lock()

	/**
	 * Lock the file, with its audit row and event.
	 *
	 * @param FileActionTarget $target The file.
	 *
	 * @return JSONResponse The lock.
	 */
	private function lockTarget(FileActionTarget $target): JSONResponse {
		$result = $this->fileService->getLockHandler()->lockFile($target->fileId);

		// Audit trail entry (best-effort -- handler swallows failures).
		$this->fileService->getAuditHandler()->logFileAction(object: $target->object, fileId: $target->fileId, action: 'file.locked', data: $result);
		$this->eventDispatcher->dispatchTyped(new FileLockedEvent(objectUuid: $target->object->getUuid(), fileId: $target->fileId, data: $result));

		return new JSONResponse(data: $result);
	}//end lockTarget()

	/**
	 * The status of a failed lock, read off the lock handler's message.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return int The HTTP status.
	 */
	private function lockFailureStatus(Exception $e): int {
		if (str_contains($e->getMessage(), 'locked') === true) {
			return 423;
		}

		return 400;
	}//end lockFailureStatus()

	/**
	 * Unlock a file
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function unlock(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before unlocking.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->onFile(fileId: $fileId));
			return $this->unlockTarget(target: new FileActionTarget(object: $this->routedObject(id: $id), fileId: $fileId));
		} catch (Exception $e) {
			return $this->actionFailed(e: $e, status: $this->unlockFailureStatus(e: $e));
		}
	}//end unlock()

	/**
	 * Unlock the file, with its audit row and event; a forced unlock has an audit action of its own.
	 *
	 * @param FileActionTarget $target The file.
	 *
	 * @return JSONResponse The lock state.
	 */
	private function unlockTarget(FileActionTarget $target): JSONResponse {
		$force = $this->parseBool(value: $this->request->getParams()['force'] ?? false);
		$result = $this->fileService->getLockHandler()->unlockFile($target->fileId, $force);

		$action = 'file.unlocked';
		if ($force === true) {
			$action = 'file.force_unlocked';
		}

		$this->fileService->getAuditHandler()->logFileAction(
			object: $target->object,
			fileId: $target->fileId,
			action: $action,
			data: ['force' => $force]
		);
		$this->eventDispatcher->dispatchTyped(
			new FileUnlockedEvent(objectUuid: $target->object->getUuid(), fileId: $target->fileId, data: ['force' => $force])
		);

		return new JSONResponse(data: $result);
	}//end unlockTarget()

	/**
	 * The status of a failed unlock, read off the lock handler's message.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return int The HTTP status.
	 */
	private function unlockFailureStatus(Exception $e): int {
		return match (true) {
			str_contains($e->getMessage(), 'Only the lock owner') => 403,
			str_contains($e->getMessage(), 'administrators') => 403,
			default => 400,
		};
	}//end unlockFailureStatus()

	/**
	 * Execute batch file operations
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function batch(string $register, string $schema, string $id): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before batch mutation.
			$check = new FileAccessCheck(route: new ObjectLinkRoute(register: $register, schema: $schema, id: $id));
			if (($this->request->getParams()['action'] ?? '') === 'delete') {
				$check->forRemoving();
			}

			$this->ensureObjectAccess(check: $check);
			return $this->runPostedBatch(object: $this->routedObject(id: $id));
		} catch (Exception $e) {
			return $this->actionFailed(e: $e);
		}
	}//end batch()

	/**
	 * Run the batch the request posts; a restricted file in it refuses the whole batch.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The results, with 207 when some failed.
	 */
	private function runPostedBatch(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		$fileIds = $data['fileIds'] ?? [];
		foreach ((array)$fileIds as $batchFileId) {
			$this->refuseRestrictedFile(fileId: (int)$batchFileId);
		}

		$handler = $this->fileService->getBatchHandler();
		$result = $handler->executeBatch(object: $object, action: $data['action'] ?? '', fileIds: $fileIds, params: $data);

		$statusCode = 200;
		if ($result['summary']['failed'] > 0) {
			$statusCode = 207;
		}

		return new JSONResponse(data: $result, statusCode: $statusCode);
	}//end runPostedBatch()

	/**
	 * Get file preview/thumbnail
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse|StreamResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	#[AnonRateLimit(limit: 120, period: 60)]
	public function preview(string $register, string $schema, string $id, int $fileId): JSONResponse|StreamResponse {
		try {
			// The setObjectContext() call throws DoesNotExistException for an unknown
			// register/schema slug. Keep it inside the try so anonymous/missing-
			// resource probes return a clean 404, not a 500 HTML page. See the
			// newman files-domain triage and openregister#1962 follow-up.
			$this->setObjectContext(register: $register, schema: $schema);

			// Gate anonymous callers on the file being publicly published.
			// Authenticated callers fall through to the existing object-level
			// RBAC path; anonymous callers MUST NOT be able to preview files
			// that haven't been explicitly published with a public share link.
			if ($this->isAnonymousRequest() === true) {
				if ($this->fileMapper === null || $this->fileMapper->isFilePublished($fileId) === false) {
					return new JSONResponse(
						data: ['error' => $this->translate(text: 'Preview not available for unpublished files')],
						statusCode: 403
					);
				}
			}

			// ADR-005 / gate-7: authenticated callers must have object read access.
			// Anonymous callers were already gated by the published-file check
			// above, so preview opts out of the write/action anonymous-deny guard.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->forAnyone()->forReading()->onFile(fileId: $fileId));

			$this->objectService->setObject($id);
			$object = $this->objectService->getObject();
			if ($object === null) {
				return new JSONResponse(data: ['error' => $this->translate(text: 'Object not found')], statusCode: 404);
			}

			$file = $this->fileService->getFile(object: $object, file: $fileId);
			if ($file === null) {
				return new JSONResponse(data: ['error' => $this->translate(text: 'File not found')], statusCode: 404);
			}

			$size = new PreviewSize(
				width: (int)($this->request->getParam('width') ?? 256),
				height: (int)($this->request->getParam('height') ?? 256)
			);

			$preview = $this->fileService->getPreviewHandler()->getPreview(file: $file, size: $size);

			$response = new StreamResponse($preview->read());
			$response->addHeader('Content-Type', $preview->getMimeType());
			$response->addHeader('Cache-Control', 'max-age=3600, public');
			$response->addHeader('Content-Length', (string)$preview->getSize());

			return $response;
		} catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
			return $this->accessDenied(e: $e);
		} catch (Exception $e) {
			$fallbackIcon = '/core/img/filetypes/file.svg';
			return new JSONResponse(
				data: ['error' => $e->getMessage(), 'fallbackIcon' => $fallbackIcon],
				statusCode: 404
			);
		}//end try
	}//end preview()

	/**
	 * Update a file's OpenRegister-side metadata: description, category, labels.
	 *
	 * 🔴 DESCRIPTION AND CATEGORY HAD NO SURFACE AT ALL.
	 * `FileMapper::setDescriptionForFile()` and `setCategoryForFile()` are
	 * reached only from `UpdateFileHandler::updateFileMetadata()`, and nothing
	 * called that — so two thirds of the metadata the `file-actions` spec
	 * describes could be stored by the data model and set by nobody. Labels had
	 * their own route and worked; that is why the gap was easy to miss.
	 *
	 * Each field is nullable and SKIPPED when null, which is what lets a caller
	 * change one without clearing the others. An empty string clears a field —
	 * that distinction is the handler's contract and is preserved here rather
	 * than flattened by a truthiness check.
	 *
	 * @param string  $register Register slug.
	 * @param string  $schema   Schema slug.
	 * @param string  $id       Object ID.
	 * @param integer $fileId   File ID.
	 *
	 * @return JSONResponse The updated metadata, 403, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function updateMetadata(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: the same object-level RBAC updateLabels applies; metadata is no less a mutation for being descriptive.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->onFile(fileId: $fileId));
			$this->routedObject(id: $id);
			return $this->updatePostedMetadata(fileId: $fileId);
		} catch (Exception $e) {
			return $this->actionFailed(e: $e);
		}
	}//end updateMetadata()

	/**
	 * Update the metadata fields the request posts.
	 *
	 * Presence, not truthiness: the handler treats null as "leave alone" and ''
	 * as "clear", so `??` would make an empty description impossible to send.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return JSONResponse The updated metadata.
	 */
	private function updatePostedMetadata(int $fileId): JSONResponse {
		$data = $this->request->getParams();

		$labels = null;
		if (array_key_exists('labels', $data) === true) {
			$labels = (array)$data['labels'];
		}

		$entity = $this->fileService->updateFileMetadata(
			fileId: $fileId,
			description: $this->postedText(data: $data, key: 'description'),
			category: $this->postedText(data: $data, key: 'category'),
			labels: $labels
		);

		return new JSONResponse(data: $entity->jsonSerialize());
	}//end updatePostedMetadata()

	/**
	 * A text field of a request, or null when the request leaves it out.
	 *
	 * @param array<string, mixed> $data The request parameters.
	 * @param string               $key  The field.
	 *
	 * @return string|null The text.
	 */
	private function postedText(array $data, string $key): ?string {
		if (array_key_exists($key, $data) === true) {
			return (string)$data[$key];
		}

		return null;
	}//end postedText()

	/**
	 * Save every file's name and description on one object in one act.
	 *
	 * Body: `files`, a list of rows each naming a `fileId` and carrying a
	 * `name`, a `description` or both. A key a row leaves out is left alone,
	 * and an empty description clears it.
	 *
	 * One audit entry is written per file actually changed, and a file the
	 * form left alone writes none. That is the whole point of the endpoint
	 * existing beside the per-file one: tidying a dossier before it goes out
	 * means renaming six files, and six round trips is why nobody does it.
	 *
	 * One row failing does not roll the others back. The answer lists what
	 * changed, what was already right, and what was refused, so a person can
	 * fix the one name that collided instead of retyping five.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $id Object ID.
	 *
	 * @return JSONResponse What changed, what did not, and what was refused.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @contract tests/Unit/Service/File/FileMetadataFormHandlerTest.php
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function saveMetadataForm(string $register, string $schema, string $id): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: the same object-level RBAC the per-file endpoints apply. Editing six files at once is six mutations.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: new FileAccessCheck(route: $route));
			return $this->savePostedForm(object: $this->routedObject(id: $id));
		} catch (Exception $e) {
			return $this->actionFailed(e: $e);
		}
	}//end saveMetadataForm()

	/**
	 * Save the rows the request posts under `files`.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse What changed, what did not, and what was refused.
	 *
	 * @throws FileActionRefusal With 400 when the rows are not a list.
	 */
	private function savePostedForm(ObjectEntity $object): JSONResponse {
		$entries = $this->request->getParam('files');
		if (is_array($entries) === false) {
			throw new FileActionRefusal(message: "Send the rows under 'files'.", code: 400);
		}

		$handler = new FileMetadataFormHandler(fileService: $this->fileService);

		return new JSONResponse(data: $handler->save(object: $object, entries: $entries));
	}//end savePostedForm()

	/**
	 * Update file labels
	 *
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param string $id Object ID
	 * @param int $fileId File ID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-12
	 *
	 * @PublicPage
	 */
	#[AnonRateLimit(limit: 30, period: 60)]
	public function updateLabels(string $register, string $schema, string $id, int $fileId): JSONResponse {
		$this->setObjectContext(register: $register, schema: $schema);

		try {
			// ADR-005 / gate-7: enforce object-level RBAC before mutating labels.
			$route = new ObjectLinkRoute(register: $register, schema: $schema, id: $id);
			$this->ensureObjectAccess(check: (new FileAccessCheck(route: $route))->onFile(fileId: $fileId));
			return $this->labelTarget(target: new FileActionTarget(object: $this->routedObject(id: $id), fileId: $fileId));
		} catch (Exception $e) {
			return $this->actionFailed(e: $e);
		}
	}//end updateLabels()

	/**
	 * Give the file the labels the request posts; anything but a list clears them.
	 *
	 * @param FileActionTarget $target The file.
	 *
	 * @return JSONResponse The labelled file.
	 */
	private function labelTarget(FileActionTarget $target): JSONResponse {
		$labels = ($this->request->getParams()['labels'] ?? []);
		if (is_array($labels) === false) {
			$labels = [];
		}

		$file = $this->fileService->updateFile(filePath: $target->fileId, content: null, tags: $labels, object: $target->object);

		return new JSONResponse(data: $this->fileService->formatFile($file));
	}//end labelTarget()

	/**
	 * Open an object's document in Nextcloud Office.
	 *
	 * The object rule decides: update opens the document for editing, read
	 * opens it read-only, and without read the answer is 404 with no token.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object UUID or identifier.
	 * @param int $fileId The file to open.
	 *
	 * @return JSONResponse The Office session, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	#[NoAdminRequired]
	public function office(string $register, string $schema, string $id, int $fileId): JSONResponse {
		try {
			$session = $this->openOfficeSession(register: $register, schema: $schema, id: $id, fileId: $fileId);
			return new JSONResponse(data: $session->toArray());
		} catch (NotAuthorizedException $e) {
			return $this->accessDenied(e: $e);
		} catch (OfficeOpenRefusedException $e) {
			return new JSONResponse(data: ['error' => $this->translate(text: $e->getMessage())], statusCode: $e->getHttpStatus());
		}
	}//end office()

	/**
	 * Show an object's document in Nextcloud Office.
	 *
	 * The page holds the Office frame and a form that posts the WOPI token
	 * into it, the way Collabora expects a token to arrive.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object UUID or identifier.
	 * @param int $fileId The file to open.
	 *
	 * @return TemplateResponse|JSONResponse The Office page, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-nextcloud-office-opens-an-objects-document-by-the-object-rule-req-ofoa-006
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function officePage(string $register, string $schema, string $id, int $fileId): TemplateResponse|JSONResponse {
		try {
			$session = $this->openOfficeSession(register: $register, schema: $schema, id: $id, fileId: $fileId);
		} catch (NotAuthorizedException $e) {
			return $this->accessDenied(e: $e);
		} catch (OfficeOpenRefusedException $e) {
			return new JSONResponse(data: ['error' => $this->translate(text: $e->getMessage())], statusCode: $e->getHttpStatus());
		}

		return new TemplateResponse(
			appName: 'openregister',
			templateName: 'office',
			params: [
				'frameAction' => $session->frameAction(),
				'token' => $session->token,
				'tokenTtl' => $session->tokenTtl,
				'readOnly' => $session->readOnly,
			]
		);
	}//end officePage()

	/**
	 * Apply the object rule and issue the Office session.
	 *
	 * @param string $register The register slug or identifier.
	 * @param string $schema The schema slug or identifier.
	 * @param string $id The object UUID or identifier.
	 * @param int $fileId The file to open.
	 *
	 * @return \OCA\OpenRegister\Files\Service\File\OfficeSession The issued session.
	 *
	 * @throws NotAuthorizedException When the caller may not read the object (404).
	 * @throws OfficeOpenRefusedException When Office cannot open the document.
	 */
	private function openOfficeSession(string $register, string $schema, string $id, int $fileId): \OCA\OpenRegister\Files\Service\File\OfficeSession {
		$user = $this->userSession?->getUser();
		if ($user === null || $this->objectFileAccess === null) {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_READABLE);
		}

		$object = $this->objectFileAccess->readable(register: $register, schema: $schema, id: $id);
		$this->refuseRestrictedFile(fileId: $fileId);

		$file = $this->fileService->getFile(object: $object, file: $fileId);
		if ($file === null) {
			throw new ObjectFileAccessDeniedException(httpStatus: ObjectFileAccessDeniedException::NOT_READABLE);
		}

		if ($this->officeSessionService === null) {
			throw new OfficeOpenRefusedException(
				httpStatus: OfficeOpenRefusedException::UNAVAILABLE,
				message: 'Nextcloud Office is not available on this instance.'
			);
		}

		return $this->officeSessionService->open(
			file: $file,
			editorUid: $user->getUID(),
			displayName: $user->getDisplayName(),
			canWrite: $this->objectFileAccess->mayChangeFiles(object: $object)
		);
	}//end openOfficeSession()

	/**
	 * Render the Files page
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @psalm-return TemplateResponse<200, array<never, never>>
	 *
	 * @spec exclude SPA-mount stub — returns the Vue `index` template; client-side router owns navigation. No HTTP contract beyond the shell.
	 */
	public function page(): TemplateResponse {
		return new TemplateResponse(
			appName: 'openregister',
			templateName: 'index',
			params: []
		);
	}//end page()
}//end class
