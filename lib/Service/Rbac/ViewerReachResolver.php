<?php

/**
 * Who is asking, and what the views they can see grant them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Exception\ViewAlertRecipientException;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the caller's reach over views, and answers what one view grants them.
 *
 * WHY THIS IS NOT IN THE CONTROLLER. `ViewsController` asked the same two
 * questions from eleven places: who is signed in, and how far do they reach.
 * The first was eight copies of the same five lines, and a copy is a place
 * the next reader has to check separately. The second needed an
 * `IGroupManager`, an `IUserSession` and a `ViewShareResolver` in a class
 * whose job is to render JSON.
 *
 * Keeping the authorization question in one object also means there is one
 * place to read when the answer is wrong, and one place a test can drive
 * without standing up a controller.
 *
 * @spec openspec/specs/saved-search-views/spec.md
 */
class ViewerReachResolver implements ViewerReachResolverInterface, ViewerReachCheckInterface {

	/**
	 * The stateless resolver that reads a view's own share block, made on first use.
	 *
	 * @var ViewShareResolver|null
	 */
	private ?ViewShareResolver $shares = null;

	/**
	 * Constructor.
	 *
	 * @param CallerContext     $caller      Who is signed in, their groups, and whether they administer the instance.
	 * @param LoggerInterface   $logger      Where an unreadable membership is noted.
	 * @param IUserManager|null $userManager Which users exist, for user shares; without it no user share is accepted.
	 */
	public function __construct(
		private readonly CallerContext $caller,
		private readonly LoggerInterface $logger,
		private readonly ?IUserManager $userManager=null,
	) {
	}//end __construct()

	/**
	 * The resolver that reads a view's own share block.
	 *
	 * @return ViewShareResolver The resolver.
	 */
	private function shares(): ViewShareResolver {
		return $this->shares ??= new ViewShareResolver();
	}//end shares()

	/**
	 * Findings for a share list about to be written, against the users and groups that exist.
	 *
	 * @param mixed $sharedWith The declared share list.
	 *
	 * @return array<int, array{code: string, message: string}> The findings; empty when the list may be stored.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function shareFindings(mixed $sharedWith): array {
		return $this->shares()->validateShares(
			sharedWith: $sharedWith,
			lookup: new ShareTargetLookup(
				groupExists: fn (string $gid): bool => $this->caller->groupManager->groupExists($gid),
				userExists: fn (string $uid): bool => $this->userManager?->userExists($uid) === true
			)
		);
	}//end shareFindings()

	/**
	 * The signed-in caller's uid, or an empty string when nobody is signed in.
	 *
	 * An empty string rather than null because every call site turned null
	 * into exactly that, and eight copies of the same conversion is eight
	 * places for one of them to convert it differently.
	 *
	 * @return string The uid, or ''.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function currentUid(): string {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentUid()

	/**
	 * How far one caller reaches: their groups, and whether they administer.
	 *
	 * An unreadable membership is NOT an authorization. It answers no groups
	 * and no administration, so the caller sees their own views and the public
	 * ones and nothing else, which is the fail-closed direction.
	 *
	 * @param string $userId The caller.
	 *
	 * @return ViewerReach The caller's reach.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function reachOf(string $userId): ViewerReach {
		$groups = [];
		$isAdmin = false;

		try {
			$isAdmin = ($this->caller->groupManager->isAdmin($userId) === true);
			$user = $this->caller->userSession->getUser();
			if ($user !== null) {
				$groups = $this->caller->groupManager->getUserGroupIds($user);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ViewerReachResolver] Could not read the caller\'s groups; treating them as holding none: '
				. $e->getMessage()
			);

			return new ViewerReach(userId: $userId, groups: [], isAdmin: false);
		}

		return new ViewerReach(userId: $userId, groups: $groups, isAdmin: $isAdmin);
	}//end reachOf()

	/**
	 * Whether a view reaches this caller at all: owned, shared with one of their groups, public, or an administrator.
	 *
	 * @param array<string, mixed> $view  The serialised view.
	 * @param ViewerReach          $reach The caller's reach.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function reaches(array $view, ViewerReach $reach): bool {
		if ($reach->isAdmin === true) {
			return true;
		}

		return $this->shares()->accessOf(view: $view, reach: $reach) !== null;
	}//end reaches()

	/**
	 * The fields of an update this caller may NOT make to one view.
	 *
	 * The three questions the endpoint used to ask separately, answered
	 * together: what the view grants this caller, whether they may administer
	 * it, and which of the fields they sent that combination refuses. Asking
	 * them one at a time from the controller left the third free to be called
	 * with the wrong answer to the first two.
	 *
	 * @param ReachedView          $reached The serialised view and the caller's reach.
	 * @param array<string, mixed> $update  The fields the caller sent; only those whose value changes are judged.
	 *
	 * @return array<int, string> The refused field names, empty when the update may proceed.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function refusedFields(ReachedView $reached, array $update): array {
		// An owner or an administrator may change everything: no difference
		// to compute.
		if ($this->shares()->mayAdministerAs(view: $reached->view, reach: $reached->reach) === true) {
			return [];
		}

		$access = $this->shares()->accessOf(view: $reached->view, reach: $reached->reach);

		return $this->shares()->refusedFields(
			update: $this->shares()->changedFields(update: $update, view: $reached->view),
			access: ($access ?? '')
		);
	}//end refusedFields()

	/**
	 * Refuse a save whose alert names recipients this caller may not add.
	 *
	 * The view is judged as it will be saved: the stored view (or, on create,
	 * just its owner) with the body's `isPublic` and `sharedWith` laid over it;
	 * a caller replacing the whole view (PUT) lays over the `isPublic` the save
	 * will write. The stored alert's recipients are not judged as additions, but
	 * one the save itself stops reaching is refused.
	 *
	 * @param ReachedView          $reached The stored view (or `['owner' => <uid>]` on create) and the caller's reach.
	 * @param array<string, mixed> $data    The request body.
	 *
	 * @return void
	 *
	 * @throws ViewAlertRecipientException When a recipient may not be added.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function assertMayNameRecipients(ReachedView $reached, array $data): void {
		$refused = $this->refusedOnSave(reached: $reached, data: $data);
		if ($refused !== []) {
			throw new ViewAlertRecipientException(recipients: $refused);
		}
	}//end assertMayNameRecipients()

	/**
	 * The recipients a save may not leave on the view's alert.
	 *
	 * @param ReachedView          $reached The stored view and the caller's reach.
	 * @param array<string, mixed> $data    The request body.
	 *
	 * @return array<int, string> The refused recipients.
	 */
	private function refusedOnSave(ReachedView $reached, array $data): array {
		$view     = $reached->view;
		$previous = array_values(array_filter((array)($view['alert']['recipients'] ?? []), 'is_string'));
		$saved    = array_merge($view, array_intersect_key($data, ['isPublic' => true, 'sharedWith' => true]));
		$refused  = $this->refusedRecipients(
			reached: new ReachedView(view: $saved, reach: $reached->reach),
			change: new AlertRecipientChange(alert: ViewAlert::declaredIn(body: $data), previous: $previous)
		);

		// 🔴 A SAVE THAT NARROWS REACH RE-JUDGES THE RECIPIENTS ALREADY STORED.
		// Stored recipients are exempt from the "may this caller add them" test,
		// and an update with no `alert` key declares none, so un-publishing a
		// view or removing a share left a recipient who could no longer read it.
		// A stored recipient who read the view as stored but would not read it as
		// saved is refused here, so the owner removes them in the same request
		// rather than leaving them on an alert about a view they cannot open.
		foreach ($previous as $recipient) {
			if ($this->recipientReads(view: $view, recipient: $recipient) === true
				&& $this->recipientReads(view: $saved, recipient: $recipient) === false
			) {
				$refused[] = $recipient;
			}
		}

		return array_values(array_unique($refused));
	}//end refusedOnSave()

	/**
	 * The alert recipients this caller may not add to one view.
	 *
	 * An alert tells its recipients the view's name and count, so a recipient
	 * must be someone the view already reaches. The owner and an administrator
	 * may add any user or group the view reaches; a write member may add only
	 * themselves. Recipients already on the stored alert are not judged again,
	 * so a member can change the threshold of an alert the owner set up.
	 *
	 * @param ReachedView          $reached The view as it will be saved (owner, isPublic, sharedWith) and the caller's reach.
	 * @param AlertRecipientChange $change  The declared alert (null when the body carries none) and the recipients on the stored alert.
	 *
	 * @return array<int, string> The refused recipients, empty when the alert may be saved.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function refusedRecipients(ReachedView $reached, AlertRecipientChange $change): array {
		$declared = ($change->alert['recipients'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$refused = $this->refusedAmong(recipients: self::newRecipients(declared: $declared, previous: $change->previous), reached: $reached);

		return array_values(array_unique($refused));
	}//end refusedRecipients()

	/**
	 * The declared recipients that are strings and not already on the stored alert, in order.
	 *
	 * @param array<int, mixed>  $declared The declared recipients.
	 * @param array<int, string> $previous The recipients on the stored alert.
	 *
	 * @return array<int, string> The recipients to judge.
	 */
	private static function newRecipients(array $declared, array $previous): array {
		$new = [];
		foreach ($declared as $recipient) {
			if (is_string($recipient) === true && in_array($recipient, $previous, true) === false) {
				$new[] = $recipient;
			}
		}

		return $new;
	}//end newRecipients()

	/**
	 * The recipients this caller may not add: for the owner or an administrator those the view
	 * does not reach, for anyone else everybody but themselves.
	 *
	 * @param array<int, string> $recipients The recipients to judge.
	 * @param ReachedView        $reached    The view as it will be saved and the caller's reach.
	 *
	 * @return array<int, string> The refused recipients.
	 */
	private function refusedAmong(array $recipients, ReachedView $reached): array {
		if ($this->shares()->mayAdministerAs(view: $reached->view, reach: $reached->reach) === true) {
			return array_filter(
				$recipients,
				fn (string $recipient): bool => $this->recipientReads(view: $reached->view, recipient: $recipient) === false
			);
		}

		$self = ViewAlert::USER_PREFIX.$reached->reach->userId;

		return array_filter($recipients, static fn (string $recipient): bool => $recipient !== $self);
	}//end refusedAmong()

	/**
	 * Whether a recipient, a `user:<uid>` or a group id, may read the view.
	 *
	 * @param array<string, mixed> $view      The view.
	 * @param string               $recipient The recipient.
	 *
	 * @return bool True when the view reaches every person the recipient names.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function recipientReads(array $view, string $recipient): bool {
		if (str_starts_with($recipient, ViewAlert::USER_PREFIX) === false) {
			// A group reads the view when the view is shared with it or public.
			return $this->shares()->accessOf(view: $view, reach: new ViewerReach(userId: '', groups: [$recipient], isAdmin: false)) !== null;
		}

		$uid = substr($recipient, strlen(ViewAlert::USER_PREFIX));
		if ($uid === '') {
			return false;
		}

		return $this->userReads(view: $view, uid: $uid);
	}//end recipientReads()

	/**
	 * Whether one user may read the view: an administrator always, anyone else through the view's shares.
	 *
	 * A membership that cannot be read refuses the user.
	 *
	 * @param array<string, mixed> $view The view.
	 * @param string               $uid  The user.
	 *
	 * @return bool True when the view reaches the user.
	 */
	private function userReads(array $view, string $uid): bool {
		try {
			if ($this->caller->groupManager->isAdmin($uid) === true) {
				return true;
			}

			$groups = $this->shareGroupsOf(view: $view, uid: $uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ViewerReachResolver] Could not read the groups of alert recipient {uid}; refusing it: {error}',
				['uid' => $uid, 'error' => $e->getMessage()]
			);

			return false;
		}

		return $this->shares()->accessOf(view: $view, reach: new ViewerReach(userId: $uid, groups: $groups, isAdmin: false)) !== null;
	}//end userReads()

	/**
	 * The view's share groups the user is a member of.
	 *
	 * @param array<string, mixed> $view The view.
	 * @param string               $uid  The user.
	 *
	 * @return array<int, string> The group ids.
	 *
	 * @throws Throwable When a membership cannot be read.
	 */
	private function shareGroupsOf(array $view, string $uid): array {
		return array_values(
			array_filter(
				$this->shares()->shareGroups(view: $view),
				fn (string $gid): bool => $this->caller->groupManager->isInGroup($uid, $gid) === true
			)
		);
	}//end shareGroupsOf()

}//end class
