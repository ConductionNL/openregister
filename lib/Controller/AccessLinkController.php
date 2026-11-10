<?php

/**
 * Access links: three endpoints the holder uses, four the owner does.
 *
 * The holder reads, comments and uploads; the owner mints, lists, switches off
 * and revokes.
 *
 * The public endpoints are public in the Nextcloud sense only. They carry no
 * session, so they are reachable without one, and what they serve is decided by
 * the link row rather than by any account.
 *
 * THE FOUR ANSWERS, AND WHY EACH ONE.
 *
 * - **404** for an unknown, revoked, switched-off or expired anchor, and for a
 *   subject that is no longer there. All five are the same answer on purpose. A
 *   403 would confirm that the record exists, which is the one fact a revoked
 *   link should stop telling, and a reduced page is worse still because a
 *   partial answer looks like the whole answer.
 * - **401** when the link is live and carries a password that was not given or
 *   did not verify. This is the one place a distinct answer is correct: whoever
 *   holds the anchor already knows the link exists, so the 401 leaks nothing,
 *   and without it there is no way to ask for the password.
 * - **403** when the link is live and open, and the act is not in the set it
 *   declared. Upload through a read-and-comment link is refused, and saying so
 *   is right: the holder is entitled to know what their own link does.
 * - **410** is deliberately never sent. Gone confirms that a link once existed
 *   and stopped, which is exactly the fact a revoked link must not disclose, so
 *   revocation answers 404 and the distinction is not offered.
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
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\AccessLinkControllerServices;
use OCA\OpenRegister\Controller\Support\AccessLinkPlatform;
use OCA\OpenRegister\Controller\Support\ObjectShareLinkThrottle;
use OCA\OpenRegister\Controller\Support\TrimmedRequestParam;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfacesInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkActsInterface;
use OCA\OpenRegister\Service\Sharing\LinkAct;
use OCA\OpenRegister\Service\Sharing\LinkFile;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuardInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkTerms;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;
use OCA\OpenRegister\Service\Sharing\LinkMintRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Access-link controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
class AccessLinkController extends Controller {

	/**
	 * Brute-force throttler action for rejected anchors and passwords.
	 *
	 * @var string
	 */
	public const THROTTLE_ACTION = ThrottledSurfacesInterface::ACCESS_LINK;

	/**
	 * The link lifecycle.
	 *
	 * @var AccessLinkServiceInterface
	 */
	private readonly AccessLinkServiceInterface $links;

	/**
	 * Serves what a link opens.
	 *
	 * @var AccessLinkReaderInterface
	 */
	private readonly AccessLinkReaderInterface $reader;

	/**
	 * Decides whether a caller may publish a subject.
	 *
	 * @var AccessLinkMintGuardInterface
	 */
	private readonly AccessLinkMintGuardInterface $mintGuard;

	/**
	 * Performs the comment and the upload, as the link.
	 *
	 * @var AccessLinkActsInterface
	 */
	private readonly AccessLinkActsInterface $acts;

	/**
	 * The current session, on the owner endpoints.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Counts rejected anchors and passwords against the brute-force throttler.
	 *
	 * @var ObjectShareLinkThrottle
	 */
	private readonly ObjectShareLinkThrottle $rejections;

	/**
	 * PSR logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Current request.
	 * @param AccessLinkControllerServices $services The access link controller services.
	 */
	public function __construct(
		IRequest $request,
		AccessLinkControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->links = $services->domain->links;
		$this->reader = $services->domain->reader;
		$this->mintGuard = $services->domain->mintGuard;
		$this->acts = $services->domain->acts;
		$this->userSession = $services->platform->userSession;
		$this->logger = $services->platform->logger;
		$this->rejections = self::rejectionCounter(platform: $services->platform);
	}//end __construct()

	/**
	 * The counter of rejected anchors and passwords on this surface.
	 *
	 * @param AccessLinkPlatform $platform The throttler and logger
	 *
	 * @return ObjectShareLinkThrottle The counter
	 */
	private static function rejectionCounter(AccessLinkPlatform $platform): ObjectShareLinkThrottle {
		return new ObjectShareLinkThrottle(
			throttler: $platform->throttler,
			logger: $platform->logger,
			action: self::THROTTLE_ACTION,
			label: 'AccessLinkController'
		);
	}//end rejectionCounter()

	/**
	 * GET /api/public/links/{anchor}
	 *
	 * Serve what the link opens, to somebody with no account.
	 *
	 * @param string $anchor The random anchor from the URL.
	 *
	 * @return JSONResponse The subject, 401 when a password is wanted, or 404.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function open(string $anchor): JSONResponse {
		$link = $this->admit(anchor: $anchor, capability: AccessLink::CAP_READ);
		if ($link instanceof AccessLink === false) {
			return $link;
		}

		// Resolved once and threaded through: the reader needs it to serve the
		// subject and the audit entry needs it to name what was read.
		$object = StoredEntity::object(object: $this->reader->subjectObject(link: $link));

		$body = $this->reader->read(link: $link, object: $object);
		if ($body === null) {
			return $this->rejected();
		}

		$this->links->recordUse(link: $link, use: $this->useOf(act: AccessLinkServiceInterface::ACT_READ, object: $object));

		return new JSONResponse(array_merge(['link' => $link->publicDescriptor()], $body));
	}//end open()

	/**
	 * POST /api/public/links/{anchor}/comments
	 *
	 * Leave a comment through a link that declares `comment`.
	 *
	 * The comment is attributed to the link, never to whoever minted it, and it
	 * is written as a public timeline entry: a holder with no account cannot
	 * write into the internal half of a record.
	 *
	 * @param string $anchor The random anchor from the URL.
	 *
	 * @return JSONResponse The comment, 400 on an empty message, 401, 403 or 404.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function comment(string $anchor): JSONResponse {
		$act = $this->admitWithSubject(anchor: $anchor, capability: AccessLink::CAP_COMMENT);
		if ($act instanceof LinkAct === false) {
			return $act;
		}

		$message = trim((string)$this->request->getParam('message', ''));
		if ($message === '') {
			return $this->badRequest(message: 'A comment needs a message.');
		}

		return $this->postComment(act: $act, message: $message);
	}//end comment()

	/**
	 * Post a comment on the link's subject and record the use.
	 *
	 * @param LinkAct $act     The admitted link and its subject
	 * @param string  $message The trimmed, non-empty comment
	 *
	 * @return JSONResponse 201 with the note, or a 400 when saving failed
	 */
	private function postComment(LinkAct $act, string $message): JSONResponse {
		try {
			$note = $this->acts->comment(act: $act, message: $message);
		} catch (Throwable $failure) {
			$this->logger->warning('[AccessLinkController] A link comment failed: ' . $failure->getMessage());

			return $this->badRequest(message: 'The comment could not be saved.');
		}

		$this->links->recordUse(link: $act->link, use: $this->useOf(act: AccessLinkServiceInterface::ACT_COMMENT, object: $act->object));

		return new JSONResponse($note, Http::STATUS_CREATED);
	}//end postComment()

	/**
	 * POST /api/public/links/{anchor}/files
	 *
	 * Add a file through a link that declares `upload`.
	 *
	 * A link that declares read and comment answers 403 here, which is the
	 * refusal the spec names: an adviser who may read and comment may not
	 * upload, and the link says so rather than the file quietly not arriving.
	 *
	 * @param string $anchor The random anchor from the URL.
	 *
	 * @return JSONResponse The stored file, 400 on a bad request, 401, 403 or 404.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function upload(string $anchor): JSONResponse {
		$act = $this->admitWithSubject(anchor: $anchor, capability: AccessLink::CAP_UPLOAD);
		if ($act instanceof LinkAct === false) {
			return $act;
		}

		$file = $this->uploadedFile();
		if ($file instanceof LinkFile === false) {
			return $file;
		}

		return $this->storeUpload(act: $act, file: $file);
	}//end upload()

	/**
	 * Read the uploaded file from the request.
	 *
	 * @return LinkFile|JSONResponse The file, or a 400 when the name or content is missing or unreadable
	 */
	private function uploadedFile(): LinkFile | JSONResponse {
		$fileName = $this->stringParam(name: 'name');
		$content = $this->request->getParam('content', null);
		if ($fileName === null || is_string($content) === false) {
			return $this->badRequest(message: 'An upload needs a name and content.');
		}

		$content = $this->decodedContent(content: $content);
		if ($content === false) {
			return $this->badRequest(message: 'The upload content is not valid base64.');
		}

		return new LinkFile(fileName: $fileName, content: $content);
	}//end uploadedFile()

	/**
	 * Decode the upload content as the request says it is encoded.
	 *
	 * A browser cannot put binary bytes in a JSON body, so the holder's page
	 * sends `encoding: base64`. Without it the content is stored as sent, as it
	 * always was.
	 *
	 * @param string $content The content as sent
	 *
	 * @return string|false The bytes, or false for unreadable base64
	 */
	private function decodedContent(string $content): string | false {
		if ($this->stringParam(name: 'encoding') !== 'base64') {
			return $content;
		}

		return base64_decode($content, true);
	}//end decodedContent()

	/**
	 * Store an uploaded file on the link's subject and record the use.
	 *
	 * @param LinkAct  $act  The admitted link and its subject
	 * @param LinkFile $file The uploaded file
	 *
	 * @return JSONResponse 201 with the stored file, or a 400 when storing failed
	 */
	private function storeUpload(LinkAct $act, LinkFile $file): JSONResponse {
		try {
			$stored = $this->acts->upload(act: $act, file: $file);
		} catch (Throwable $failure) {
			$this->logger->warning('[AccessLinkController] A link upload failed: ' . $failure->getMessage());

			return $this->badRequest(message: 'The file could not be stored.');
		}

		$this->recordUpload(act: $act, file: $file);

		return new JSONResponse($stored, Http::STATUS_CREATED);
	}//end storeUpload()

	/**
	 * Record an upload through the link, naming the stored file.
	 *
	 * @param LinkAct  $act  The admitted link and its subject
	 * @param LinkFile $file The stored file
	 *
	 * @return void
	 */
	private function recordUpload(LinkAct $act, LinkFile $file): void {
		$this->links->recordUse(
			link: $act->link,
			use: new AccessLinkUse(
				act: AccessLinkServiceInterface::ACT_UPLOAD,
				object: $act->object,
				ipAddress: $this->request->getRemoteAddress(),
				context: ['fileName' => $file->fileName]
			)
		);
	}//end recordUpload()

	/**
	 * POST /api/access-links
	 *
	 * Mint a link over one object, view or file.
	 *
	 * THE GUARD HERE IS THE WHOLE ACCESS DECISION. Every read through the link
	 * afterwards runs with the group rules off, because there is no session for
	 * them to judge, so this is the only moment anybody asks whether this
	 * subject may be published at all. Without it, any signed-in user could
	 * publish any record by naming its uuid, and the link would keep serving it
	 * correctly for as long as it lived.
	 *
	 * A subject this caller may not read answers 404 rather than 403, for the
	 * reason `ObjectsController::show()` already chose: a 403 would confirm that
	 * the uuid exists.
	 *
	 * @return JSONResponse The minted link and its URL, 400 naming the refusal, or 404.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	#[NoAdminRequired]
	public function mint(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notFound();
		}

		$mintRequest = $this->mintRequestFor(user: $user);
		if ($mintRequest === null) {
			return $this->notFound();
		}

		return $this->minted(mintRequest: $mintRequest);
	}//end mint()

	/**
	 * The mint request for the requested subject, when the caller may publish it.
	 *
	 * @param IUser $user The caller
	 *
	 * @return LinkMintRequest|null The request, or null when the caller may not mint for the subject
	 */
	private function mintRequestFor(IUser $user): ?LinkMintRequest {
		$subjectType = (string)$this->request->getParam('subjectType', '');
		$subjectId = (string)$this->request->getParam('subjectId', '');
		if ($this->mintGuard->mayMint(subjectType: $subjectType, subjectId: $subjectId) === false) {
			return null;
		}

		return new LinkMintRequest(
			minter: $user->getUID(),
			subjectType: $subjectType,
			subjectId: $subjectId,
			terms: $this->requestedTerms()
		);
	}//end mintRequestFor()

	/**
	 * The terms the caller asks the new link to carry.
	 *
	 * @return AccessLinkTerms Capabilities, expiry, password and label
	 */
	private function requestedTerms(): AccessLinkTerms {
		return new AccessLinkTerms(
			capabilities: $this->capabilitiesParam(),
			expiresAt: $this->stringParam(name: 'expiresAt'),
			password: $this->stringParam(name: 'password'),
			label: $this->stringParam(name: 'label')
		);
	}//end requestedTerms()

	/**
	 * Mint the link and answer it.
	 *
	 * @param LinkMintRequest $mintRequest What to mint
	 *
	 * @return JSONResponse 201 with the minted link, or 400 naming the refused requirement
	 */
	private function minted(LinkMintRequest $mintRequest): JSONResponse {
		try {
			$minted = $this->links->mint($mintRequest);
		} catch (InvalidArgumentException $refused) {
			return $this->badRequest(message: $refused->getMessage());
		}

		return new JSONResponse($minted, Http::STATUS_CREATED);
	}//end minted()

	/**
	 * GET /api/access-links
	 *
	 * Every link the calling principal minted, open or closed.
	 *
	 * @return JSONResponse The links.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['results' => []]);
		}

		return new JSONResponse(['results' => $this->links->listForUser(userId: $user->getUID())]);
	}//end index()

	/**
	 * PUT /api/access-links/{id}
	 *
	 * Switch one of the calling principal's own links off, or back on.
	 *
	 * @param int $id The link row id.
	 *
	 * @return JSONResponse The updated link, or 404.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	#[NoAdminRequired]
	public function update(int $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notFound();
		}

		$updated = $this->switched(id: $id, userId: $user->getUID());

		if ($updated === null) {
			return $this->notFound();
		}

		return new JSONResponse($updated, Http::STATUS_OK);
	}//end update()

	/**
	 * Switch one of the caller's own links off or back on, as the request's `disabled` asks.
	 *
	 * @param int    $id     The link row id.
	 * @param string $userId The calling principal.
	 *
	 * @return array<string, mixed>|null The updated link, or null when the caller has no such link.
	 */
	private function switched(int $id, string $userId): ?array {
		if ((bool)$this->request->getParam('disabled', false) === true) {
			return $this->links->disable(id: $id, userId: $userId);
		}

		return $this->links->enable(id: $id, userId: $userId);
	}//end switched()

	/**
	 * DELETE /api/access-links/{id}
	 *
	 * Revoke one of the calling principal's own links. A link somebody else
	 * minted is, to this caller, a link that does not exist.
	 *
	 * @param int $id The link row id.
	 *
	 * @return JSONResponse Empty on success, or 404 when there is nothing to revoke.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	#[NoAdminRequired]
	public function revoke(int $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notFound();
		}

		if ($this->links->revoke(id: $id, userId: $user->getUID()) === false) {
			return $this->notFound();
		}

		return new JSONResponse([], Http::STATUS_OK);
	}//end revoke()

	/**
	 * Admit a holder to one act, or the refusal to send instead.
	 *
	 * The order is deliberate. Existence first, so a dead anchor never reaches
	 * the password check and cannot be told apart by timing it. The password
	 * next, so a holder who has not opened the link cannot learn what it
	 * declares. The capability last, because only somebody already through the
	 * door is entitled to be told that this door does not do that.
	 *
	 * @param string $anchor The anchor from the URL.
	 * @param string $capability The capability the act needs.
	 *
	 * @return AccessLink|JSONResponse The admitted link, or the refusal.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	private function admit(string $anchor, string $capability): AccessLink | JSONResponse {
		$link = $this->links->resolve(anchor: $anchor);
		if ($link === null) {
			return $this->rejected();
		}

		if ($this->links->passwordAccepted(link: $link, password: $this->password()) === false) {
			return $this->passwordRefused();
		}

		if ($link->allows(capability: $capability) === false) {
			return $this->capabilityRefused(link: $link);
		}

		return $link;
	}//end admit()

	/**
	 * Admit a link for a capability and resolve its subject object.
	 *
	 * @param string $anchor     The opaque anchor
	 * @param string $capability The capability the act needs
	 *
	 * @return LinkAct|JSONResponse The link and its subject, or the refusal
	 */
	private function admitWithSubject(string $anchor, string $capability): LinkAct | JSONResponse {
		$link = $this->admit(anchor: $anchor, capability: $capability);
		if ($link instanceof AccessLink === false) {
			return $link;
		}

		$object = StoredEntity::object(object: $this->reader->subjectObject(link: $link));
		if ($object === null) {
			return $this->rejected();
		}

		return new LinkAct(link: $link, object: $object);
	}//end admitWithSubject()

	/**
	 * Count a wrong or missing password and ask for it.
	 *
	 * @return JSONResponse 401 saying the link needs a password
	 */
	private function passwordRefused(): JSONResponse {
		$this->registerRejectedAttempt();

		return new JSONResponse(
			['message' => 'This link is closed with a password.', 'passwordRequired' => true],
			Http::STATUS_UNAUTHORIZED
		);
	}//end passwordRefused()

	/**
	 * Refuse an act the link does not allow, naming what it does allow.
	 *
	 * @param AccessLink $link The admitted link
	 *
	 * @return JSONResponse 403 with the link's capabilities
	 */
	private function capabilityRefused(AccessLink $link): JSONResponse {
		return new JSONResponse(
			[
				'message' => 'This link does not allow that.',
				'capabilities' => $link->declaredCapabilities(),
			],
			Http::STATUS_FORBIDDEN
		);
	}//end capabilityRefused()

	/**
	 * Count a rejected request against the throttler and answer the uniform 404.
	 *
	 * @return JSONResponse 404 response
	 */
	private function rejected(): JSONResponse {
		$this->registerRejectedAttempt();

		return $this->notFound();
	}//end rejected()

	/**
	 * The audit record of a use of the link, from the caller's address.
	 *
	 * @param string            $act    The act (read, comment, ...)
	 * @param ObjectEntity|null $object The subject acted on
	 *
	 * @return AccessLinkUse The use
	 */
	private function useOf(string $act, ?ObjectEntity $object): AccessLinkUse {
		return new AccessLinkUse(
			act: $act,
			object: $object,
			ipAddress: $this->request->getRemoteAddress()
		);
	}//end useOf()

	/**
	 * Answer a request the link cannot act on.
	 *
	 * @param string $message Why
	 *
	 * @return JSONResponse 400 response
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * The password the holder presented, from the header or the body.
	 *
	 * The header is read first so a password never has to ride in a query
	 * string, where it would land in every access log between here and the
	 * holder.
	 *
	 * @return string|null The password, or null when none was presented.
	 */
	private function password(): ?string {
		$header = trim((string)$this->request->getHeader('X-OpenRegister-Link-Password'));
		if ($header !== '') {
			return $header;
		}

		return $this->stringParam(name: 'password');
	}//end password()

	/**
	 * The uniform refusal for anything that is not there.
	 *
	 * @return JSONResponse The 404.
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
	}//end notFound()

	/**
	 * Note a rejected anchor or password against the throttler.
	 *
	 * A uniform 404 hides WHICH failure occurred; it does nothing about how
	 * fast the next guess can be attempted, and an anchor is guessable in
	 * principle even when it is not guessable in practice.
	 *
	 * @return void
	 */
	private function registerRejectedAttempt(): void {
		$this->rejections->countRejected(address: $this->request->getRemoteAddress());
	}//end registerRejectedAttempt()

	/**
	 * The capabilities named on a mint request.
	 *
	 * @return array<int, string> The requested capabilities.
	 */
	private function capabilitiesParam(): array {
		$value = $this->request->getParam('capabilities', []);

		if (is_string($value) === true) {
			$value = explode(',', $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_string'));
	}//end capabilitiesParam()

	/**
	 * A trimmed non-empty request parameter, or null.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null.
	 */
	private function stringParam(string $name): ?string {
		return TrimmedRequestParam::read(request: $this->request, name: $name);
	}//end stringParam()
}//end class
