<?php

/**
 * The consent surface — where a person sees and answers "may X act as you?".
 *
 * WHY THIS HAS TO EXIST AS A SURFACE
 *
 * A grant store with no way to answer is a store that only ever says no. Every
 * refusal `DelegationService` issues reports whether asking is the sensible next
 * step, and until a person can actually be asked, that reason is advice nobody
 * can take. So this controller is not the "UI half" of the feature — it is the
 * half that makes the refusals recoverable.
 *
 * THE AUTHORIZATION RULE, IN ONE SENTENCE
 *
 * A person answers over THEMSELVES; an administrator answers over anyone. The
 * requester is neither, and the check that enforces that lives in
 * {@see DelegationConsentService::answer()} rather than here, so it cannot be
 * bypassed by a second caller arriving through some other path later.
 *
 * 🔴 WHAT THIS ENDPOINT WILL NOT DO
 *
 * It will not let a caller name the principal. `principal` is always the session
 * user: an endpoint that accepted it would let anyone raise a request in
 * somebody else's name, and the resulting prompt would ask a real person to
 * permit a delegation to a party who never asked for it — which they would
 * reasonably read as that party asking.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Service\Delegation\DelegationConsentService;
use OCA\OpenRegister\Service\Delegation\DelegationNotifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * REST surface for requesting, answering and revoking delegations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The collaborators are the
 *   store, the lifecycle, the notifier and the three identity services the
 *   authorization rule is expressed in. Each is used once; none is optional.
 */
class DelegationController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName      The app id.
	 * @param IRequest                 $request      The request.
	 * @param DelegationGrantMapper    $grants       Reads the grant store.
	 * @param DelegationConsentService $consent      Owns the lifecycle and its rules.
	 * @param DelegationNotifier       $notifier     Tells a person they were asked.
	 * @param IUserSession             $userSession  Names the caller.
	 * @param IUserManager             $userManager  Proves the acted-as account exists.
	 * @param IGroupManager            $groupManager Distinguishes an administrator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DelegationGrantMapper $grants,
		private readonly DelegationConsentService $consent,
		private readonly DelegationNotifier $notifier,
		private readonly IUserSession $userSession,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Everything about the caller's delegations, from both sides.
	 *
	 * Both sides in one response, deliberately. "Who may act as me" and "who may
	 * I act as" are the two halves of the same question, and a person auditing
	 * their own account needs the first while a person recovering from a refusal
	 * needs the second — splitting them into two endpoints means whichever one a
	 * UI forgets to call is the half nobody ever looks at.
	 *
	 * @return JSONResponse The caller's delegations.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$caller = $this->callerUid();
		if ($caller === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'awaitingMyAnswer' => $this->describeAll(grants: $this->grants->findAwaitingAnswerBy(actingAs: $caller)),
				'overMe' => $this->describeAll(grants: $this->grants->findGrantsOver(actingAs: $caller)),
				'heldByMe' => $this->describeAll(grants: $this->grants->findHeldBy(principal: $caller)),
			]
		);
	}//end index()

	/**
	 * Ask a person to let you act as them.
	 *
	 * The principal is the SESSION USER and is never read from the body — see the
	 * class docblock.
	 *
	 * @return JSONResponse The created or reused request.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	#[NoAdminRequired]
	public function request(): JSONResponse {
		$caller = $this->callerUid();
		if ($caller === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		$body = $this->request->getParams();
		$actingAs = trim((string)($body['actingAs'] ?? ''));

		if ($this->userManager->get($actingAs) === null) {
			// Checked BEFORE the request is stored. A pending request naming an
			// account that does not exist can never be answered, so it would sit
			// in the store until it expired while its requester waited for a
			// prompt nobody could ever see.
			return new JSONResponse(
				['error' => sprintf('"%s" resolves to no account.', $actingAs)],
				Http::STATUS_NOT_FOUND
			);
		}

		try {
			$grant = $this->consent->request(
				principal: $caller,
				actingAs: $actingAs,
				scope: (array)($body['scope'] ?? []),
				reason: (string)($body['reason'] ?? ''),
				now: new DateTime()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		// Notify AFTER the store. Calling this unconditionally is safe because
		// the notification is keyed on the GRANT's uuid, and the consent service
		// reuses an outstanding request rather than creating a second one — so
		// N blocked units of work asking again produce one notification that gets
		// replaced in place, not N prompts. Deduping at this call site instead
		// would leave every other future caller free to reintroduce the storm.
		$this->notifier->requested(grant: $grant);

		return new JSONResponse($this->consent->describe($grant), Http::STATUS_CREATED);
	}//end request()

	/**
	 * Allow or deny a request to act as you.
	 *
	 * @param string $uuid The grant's uuid.
	 *
	 * @return JSONResponse The answered grant.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	#[NoAdminRequired]
	public function answer(string $uuid): JSONResponse {
		$caller = $this->callerUid();
		if ($caller === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		$grant = $this->load(uuid: $uuid);
		if ($grant === null) {
			return new JSONResponse(['error' => 'No such delegation request.'], Http::STATUS_NOT_FOUND);
		}

		$body = $this->request->getParams();

		try {
			$answered = $this->consent->answer(
				$grant,
				$caller,
				((bool)($body['allow'] ?? false)),
				new DateTime(),
				isAdmin: $this->isAdmin(uid: $caller)
			);
		} catch (InvalidArgumentException $e) {
			// 403, not 400. Being told "you may not answer this" is an
			// authorization outcome, and reporting it as a malformed request
			// sends the reader to their payload.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$this->notifier->answered(grant: $answered);

		return new JSONResponse($this->consent->describe($answered));
	}//end answer()

	/**
	 * Withdraw a delegation you previously allowed.
	 *
	 * @param string $uuid The grant's uuid.
	 *
	 * @return JSONResponse The revoked grant.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	#[NoAdminRequired]
	public function revoke(string $uuid): JSONResponse {
		$caller = $this->callerUid();
		if ($caller === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		$grant = $this->load(uuid: $uuid);
		if ($grant === null) {
			return new JSONResponse(['error' => 'No such delegation.'], Http::STATUS_NOT_FOUND);
		}

		try {
			$revoked = $this->consent->revoke(
				$grant,
				$caller,
				new DateTime(),
				isAdmin: $this->isAdmin(uid: $caller)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		$this->notifier->answered(grant: $revoked);

		return new JSONResponse($this->consent->describe($revoked));
	}//end revoke()

	/**
	 * The caller's uid, or null when there is no session.
	 *
	 * @return string|null The uid.
	 */
	private function callerUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end callerUid()

	/**
	 * Whether a uid is an administrator.
	 *
	 * @param string $uid The uid.
	 *
	 * @return boolean Whether they are an admin.
	 */
	private function isAdmin(string $uid): bool {
		return $this->groupManager->isAdmin($uid);
	}//end isAdmin()

	/**
	 * Load a grant by uuid, or null when it does not exist.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return DelegationGrant|null The grant.
	 */
	private function load(string $uuid): ?DelegationGrant {
		try {
			return $this->grants->findByUuid(uuid: $uuid);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end load()

	/**
	 * Render a list of grants through the same describe() every prompt uses.
	 *
	 * 🔴 Through `describe()`, never by serialising the entity. `describe()` is
	 * where the requester's stated reason is kept in its own attributed field and
	 * out of the sentence the system speaks in its own voice. A list endpoint
	 * that dumped the entity would hand a UI the raw string to render wherever it
	 * liked, and the separation would hold in the notification and nowhere else.
	 *
	 * @param array<int, DelegationGrant> $grants The grants.
	 *
	 * @return array<int, array> The rendered grants.
	 */
	private function describeAll(array $grants): array {
		return array_values(
			array_map(fn (DelegationGrant $grant): array => $this->consent->describe($grant), $grants)
		);
	}//end describeAll()
}//end class
