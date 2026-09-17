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
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Service\Hardening\ThrottledSurfaces;
use OCA\OpenRegister\Service\Sharing\AccessLinkActs;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Service\Sharing\AccessLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
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
	public const THROTTLE_ACTION = ThrottledSurfaces::ACCESS_LINK;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name (injected by Nextcloud).
	 * @param IRequest $request Current request.
	 * @param AccessLinkService $links The link lifecycle.
	 * @param AccessLinkReader $reader Serves what a link opens.
	 * @param AccessLinkMintGuard $mintGuard Decides whether a caller may publish a subject.
	 * @param AccessLinkActs $acts Performs the comment and the upload, as the link.
	 * @param IUserSession $userSession The current session, on the owner endpoints.
	 * @param IThrottler $throttler Brute-force throttler for rejected anchors.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AccessLinkService $links,
		private readonly AccessLinkReader $reader,
		private readonly AccessLinkMintGuard $mintGuard,
		private readonly AccessLinkActs $acts,
		private readonly IUserSession $userSession,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

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
		$object = $this->reader->subjectObject(link: $link);

		$body = $this->reader->read(link: $link, object: $object);
		if ($body === null) {
			$this->registerRejectedAttempt();

			return $this->notFound();
		}

		$this->links->recordUse(
			link: $link,
			act: AccessLinkService::ACT_READ,
			object: $object,
			ipAddress: $this->request->getRemoteAddress()
		);

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
		$link = $this->admit(anchor: $anchor, capability: AccessLink::CAP_COMMENT);
		if ($link instanceof AccessLink === false) {
			return $link;
		}

		$object = $this->reader->subjectObject(link: $link);
		if ($object === null) {
			$this->registerRejectedAttempt();

			return $this->notFound();
		}

		$message = trim((string)$this->request->getParam('message', ''));
		if ($message === '') {
			return new JSONResponse(['message' => 'A comment needs a message.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$note = $this->acts->comment(link: $link, object: $object, message: $message);
		} catch (Throwable $failure) {
			$this->logger->warning('[AccessLinkController] A link comment failed: ' . $failure->getMessage());

			return new JSONResponse(['message' => 'The comment could not be saved.'], Http::STATUS_BAD_REQUEST);
		}

		$this->links->recordUse(
			link: $link,
			act: AccessLinkService::ACT_COMMENT,
			object: $object,
			ipAddress: $this->request->getRemoteAddress()
		);

		return new JSONResponse($note, Http::STATUS_CREATED);
	}//end comment()

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
		$link = $this->admit(anchor: $anchor, capability: AccessLink::CAP_UPLOAD);
		if ($link instanceof AccessLink === false) {
			return $link;
		}

		$object = $this->reader->subjectObject(link: $link);
		if ($object === null) {
			$this->registerRejectedAttempt();

			return $this->notFound();
		}

		$fileName = $this->stringParam(name: 'name');
		$content = $this->request->getParam('content', null);
		if ($fileName === null || is_string($content) === false) {
			return new JSONResponse(
				['message' => 'An upload needs a name and content.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$stored = $this->acts->upload(
				link: $link,
				object: $object,
				fileName: $fileName,
				content: $content
			);
		} catch (Throwable $failure) {
			$this->logger->warning('[AccessLinkController] A link upload failed: ' . $failure->getMessage());

			return new JSONResponse(['message' => 'The file could not be stored.'], Http::STATUS_BAD_REQUEST);
		}

		$this->links->recordUse(
			link: $link,
			act: AccessLinkService::ACT_UPLOAD,
			object: $object,
			ipAddress: $this->request->getRemoteAddress(),
			context: ['fileName' => $fileName]
		);

		return new JSONResponse($stored, Http::STATUS_CREATED);
	}//end upload()

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

		$subjectType = (string)$this->request->getParam('subjectType', '');
		$subjectId = (string)$this->request->getParam('subjectId', '');
		if ($this->mintGuard->mayMint(subjectType: $subjectType, subjectId: $subjectId) === false) {
			return $this->notFound();
		}

		try {
			$minted = $this->links->mint(
				userId: $user->getUID(),
				subjectType: $subjectType,
				subjectId: $subjectId,
				capabilities: $this->capabilitiesParam(),
				expiresAt: $this->stringParam(name: 'expiresAt'),
				password: $this->stringParam(name: 'password'),
				label: $this->stringParam(name: 'label')
			);
		} catch (InvalidArgumentException $refused) {
			return new JSONResponse(['message' => $refused->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($minted, Http::STATUS_CREATED);
	}//end mint()

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

		$updated = $this->links->setDisabled(
			id: $id,
			userId: $user->getUID(),
			disabled: ((bool)$this->request->getParam('disabled', false))
		);

		if ($updated === null) {
			return $this->notFound();
		}

		return new JSONResponse($updated, Http::STATUS_OK);
	}//end update()

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
			$this->registerRejectedAttempt();

			return $this->notFound();
		}

		if ($this->links->passwordAccepted(link: $link, password: $this->password()) === false) {
			$this->registerRejectedAttempt();

			return new JSONResponse(
				['message' => 'This link is closed with a password.', 'passwordRequired' => true],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($link->allows(capability: $capability) === false) {
			return new JSONResponse(
				[
					'message' => 'This link does not allow that.',
					'capabilities' => $link->declaredCapabilities(),
				],
				Http::STATUS_FORBIDDEN
			);
		}

		return $link;
	}//end admit()

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
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (Throwable $throttlerFailure) {
			$this->logger->warning(
				'AccessLinkController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
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

		$named = [];
		foreach ($value as $candidate) {
			if (is_string($candidate) === true) {
				$named[] = $candidate;
			}
		}

		return $named;
	}//end capabilitiesParam()

	/**
	 * A trimmed non-empty request parameter, or null.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string|null The value, or null.
	 */
	private function stringParam(string $name): ?string {
		$value = $this->request->getParam($name, null);

		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end stringParam()
}//end class
