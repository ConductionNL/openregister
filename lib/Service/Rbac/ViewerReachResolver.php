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
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IGroupManager;
use OCP\IUserSession;
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
 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
 */
class ViewerReachResolver {

	/**
	 * The stateless resolver that reads a view's own share block.
	 *
	 * @var ViewShareResolver
	 */
	private ViewShareResolver $shares;

	/**
	 * Constructor.
	 *
	 * @param IUserSession    $userSession  Who is signed in.
	 * @param IGroupManager   $groupManager Their groups, and whether they administer the instance.
	 * @param LoggerInterface $logger       Where an unreadable membership is noted.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		$this->shares = new ViewShareResolver();
	}//end __construct()

	/**
	 * The signed-in caller's uid, or an empty string when nobody is signed in.
	 *
	 * An empty string rather than null because every call site turned null
	 * into exactly that, and eight copies of the same conversion is eight
	 * places for one of them to convert it differently.
	 *
	 * @return string The uid, or ''.
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function currentUid(): string {
		$user = $this->userSession->getUser();
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
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function reachOf(string $userId): ViewerReach {
		$groups = [];
		$isAdmin = false;

		try {
			$isAdmin = ($this->groupManager->isAdmin($userId) === true);
			$user = $this->userSession->getUser();
			if ($user !== null) {
				$groups = $this->groupManager->getUserGroupIds($user);
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
	 * The fields of an update this caller may NOT make to one view.
	 *
	 * The three questions the endpoint used to ask separately, answered
	 * together: what the view grants this caller, whether they may administer
	 * it, and which of the fields they sent that combination refuses. Asking
	 * them one at a time from the controller left the third free to be called
	 * with the wrong answer to the first two.
	 *
	 * @param array<string, mixed> $view   The serialised view.
	 * @param ViewerReach          $reach  The caller's reach.
	 * @param array<string, mixed> $update The fields the caller sent.
	 *
	 * @return array<int, string> The refused field names, empty when the update may proceed.
	 *
	 * @spec openspec/changes/view-group-share/specs/saved-search-views/spec.md
	 */
	public function refusedFields(array $view, ViewerReach $reach, array $update): array {
		$mayAdminister = $this->shares->mayAdminister(
			view: $view,
			userId: $reach->userId,
			isAdmin: $reach->isAdmin
		);

		$access = $this->shares->accessFor(
			view: $view,
			userId: $reach->userId,
			userGroups: $reach->groups
		);

		return $this->shares->refusedFields(
			update: $update,
			access: ($access ?? ''),
			mayAdminister: $mayAdminister
		);
	}//end refusedFields()

}//end class
