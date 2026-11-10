<?php

/**
 * OpenRegister Example Set Collectives Store
 *
 * The Collectives side of an example set's `collectivesPages` part: a
 * collective found by name or created (a collective is a team, so creating
 * one creates its team), the Nextcloud groups that may read it added to that
 * team, and a page found by title or created with its content. Every call
 * names the account it acts as, because Collectives answers per member.
 * The app is reached through its own services by name, so an instance
 * without Collectives loads this class and reports it unavailable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Finds, writes and deletes the collectives and pages of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
 */
class ExampleSetCollectivesStore {

	/**
	 * Collectives' collective service.
	 */
	private const COLLECTIVES = 'OCA\Collectives\Service\CollectiveService';

	/**
	 * Collectives' page service.
	 */
	private const PAGES = 'OCA\Collectives\Service\PageService';

	/**
	 * The Teams (Circles) manager a collective's team is reached through.
	 */
	private const CIRCLES = 'OCA\Circles\CirclesManager';

	/**
	 * The Circles member type of a Nextcloud group.
	 */
	private const GROUP_MEMBER = 2;

	/**
	 * The Circles member type of a Nextcloud account.
	 */
	private const USER_MEMBER = 1;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container  Hands out the Collectives and Circles services.
	 * @param IAppManager        $appManager Tells whether Collectives is installed.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Whether Collectives is installed and its services load.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function available(): bool {
		return $this->appManager->isInstalled('collectives') === true && class_exists(self::COLLECTIVES) === true && class_exists(self::PAGES) === true;
	}//end available()

	/**
	 * The collective of this name the team's owner is in, or a new one, with the team's groups added.
	 *
	 * @param CollectiveTeam $team The owner and the groups added to the collective's team.
	 * @param string         $name The collective's name.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function collectiveOf(CollectiveTeam $team, string $name): array {
		$service = $this->container->get(self::COLLECTIVES);
		try {
			$found = $service->findCollectiveByName($team->owner, $name);
			$this->addGroups(team: $team, circleId: (string) $found->getCircleId());
			return ['id' => (int) $found->getId(), 'created' => false];
		} catch (Throwable) {
			$made = $service->createCollective($team->owner, 'nl', $name)[0];
		}

		$this->addGroups(team: $team, circleId: (string) $made->getCircleId());

		return ['id' => (int) $made->getId(), 'created' => true];
	}//end collectiveOf()

	/**
	 * The top-level page of this title in the collective, or a new one with the content.
	 *
	 * @param CollectiveSeat     $seat The collective id and the account that writes the page.
	 * @param CollectivePageText $text The page title and its Markdown.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function page(CollectiveSeat $seat, CollectivePageText $text): array {
		$found = $this->titledPage(seat: $seat, title: $text->title);
		if ($found !== null) {
			return ['id' => $found, 'created' => false];
		}

		$page = $this->container->get(self::PAGES)->createBase($seat->collective, 0, $text->title, null, $seat->owner, null, $text->content);

		return ['id' => (int) $page->getId(), 'created' => true];
	}//end page()

	/**
	 * The id of the collective's page of this title, as the account reads the collective, or null.
	 *
	 * @param CollectiveSeat $seat  The collective id and the account that reads it.
	 * @param string         $title The page title.
	 *
	 * @return int|null
	 */
	private function titledPage(CollectiveSeat $seat, string $title): ?int {
		foreach ($this->container->get(self::PAGES)->findAll($seat->collective, $seat->owner) as $page) {
			if ($page->getTitle() === $title) {
				return (int) $page->getId();
			}
		}

		return null;
	}//end titledPage()

	/**
	 * Whether the page is still in the collective, as the account reads it.
	 *
	 * @param CollectiveSeat $seat The collective id and the account that reads it.
	 * @param int            $page The page id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function pageFound(CollectiveSeat $seat, int $page): bool {
		try {
			$this->container->get(self::PAGES)->find($seat->collective, $page, $seat->owner);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end pageFound()

	/**
	 * Delete a page the set wrote, without passing through the trash.
	 *
	 * @param CollectiveSeat $seat The collective id and the account that deletes the page.
	 * @param int            $page The page id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function deletePage(CollectiveSeat $seat, int $page): void {
		$this->container->get(self::PAGES)->trash($seat->collective, $page, $seat->owner, true);
	}//end deletePage()

	/**
	 * Delete a collective the set wrote, with its team.
	 *
	 * @param int    $collective The collective id.
	 * @param string $owner      The account that owns it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function deleteCollective(int $collective, string $owner): void {
		$service = $this->container->get(self::COLLECTIVES);
		$service->trashCollective($collective, $owner);
		$service->deleteCollective($collective, $owner, true);
	}//end deleteCollective()

	/**
	 * Add Nextcloud groups to a collective's team; a group already in it stays.
	 *
	 * @param CollectiveTeam $team     The account that manages the team, and the group ids.
	 * @param string         $circleId The team's id.
	 *
	 * @return void
	 */
	private function addGroups(CollectiveTeam $team, string $circleId): void {
		if ($team->groups === [] || class_exists(self::CIRCLES) === false) {
			return;
		}

		$circles = $this->container->get(self::CIRCLES);
		$circles->startSession($circles->getFederatedUser($team->owner, self::USER_MEMBER));
		$add = fn (string $group): mixed => $circles->addMember($circleId, $circles->getFederatedUser($group, self::GROUP_MEMBER));
		try {
			foreach ($team->groups as $group) {
				$this->addGroup(add: $add, group: $group);
			}
		} finally {
			$circles->stopSession();
		}
	}//end addGroups()

	/**
	 * Add one group to the circle; a group Circles refuses is left out.
	 *
	 * @param callable $add   Adds a group to the circle, through the Circles manager in the owner's session.
	 * @param string   $group The group.
	 *
	 * @return void
	 */
	private function addGroup(callable $add, string $group): void {
		try {
			$add($group);
		} catch (Throwable) {
			return;
		}
	}//end addGroup()
}//end class
