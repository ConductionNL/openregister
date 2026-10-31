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
	 * The collective of this name the owner is in, or a new one with the given groups in its team.
	 *
	 * @param string             $owner  The account that owns a new collective.
	 * @param string             $name   The collective's name.
	 * @param array<int, string> $groups Nextcloud groups added to the collective's team.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function collective(string $owner, string $name, array $groups): array {
		$service = $this->container->get(self::COLLECTIVES);
		try {
			$found = $service->findCollectiveByName($owner, $name);
			$this->addGroups(owner: $owner, circleId: (string) $found->getCircleId(), groups: $groups);
			return ['id' => (int) $found->getId(), 'created' => false];
		} catch (Throwable) {
			$made = $service->createCollective($owner, 'nl', $name)[0];
		}

		$this->addGroups(owner: $owner, circleId: (string) $made->getCircleId(), groups: $groups);

		return ['id' => (int) $made->getId(), 'created' => true];
	}//end collective()

	/**
	 * The top-level page of this title in the collective, or a new one with the content.
	 *
	 * @param int    $collective The collective id.
	 * @param string $owner      The account that writes the page.
	 * @param string $title      The page title.
	 * @param string $content    The page's Markdown.
	 *
	 * @return array{id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function page(int $collective, string $owner, string $title, string $content): array {
		$pages = $this->container->get(self::PAGES);
		foreach ($pages->findAll($collective, $owner) as $page) {
			if ($page->getTitle() === $title) {
				return ['id' => (int) $page->getId(), 'created' => false];
			}
		}

		$page = $pages->createBase($collective, 0, $title, null, $owner, null, $content);

		return ['id' => (int) $page->getId(), 'created' => true];
	}//end page()

	/**
	 * Whether the page is still in the collective.
	 *
	 * @param int    $collective The collective id.
	 * @param int    $page       The page id.
	 * @param string $owner      The account that reads it.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function pageExists(int $collective, int $page, string $owner): bool {
		try {
			$this->container->get(self::PAGES)->find($collective, $page, $owner);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end pageExists()

	/**
	 * Delete a page the set wrote, without passing through the trash.
	 *
	 * @param int    $collective The collective id.
	 * @param int    $page       The page id.
	 * @param string $owner      The account that deletes it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-write-collectives-pages
	 */
	public function deletePage(int $collective, int $page, string $owner): void {
		$this->container->get(self::PAGES)->trash($collective, $page, $owner, true);
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
	 * @param string             $owner    The account that manages the team.
	 * @param string             $circleId The team's id.
	 * @param array<int, string> $groups   The group ids.
	 *
	 * @return void
	 */
	private function addGroups(string $owner, string $circleId, array $groups): void {
		if ($groups === [] || class_exists(self::CIRCLES) === false) {
			return;
		}

		$circles = $this->container->get(self::CIRCLES);
		$circles->startSession($circles->getFederatedUser($owner, self::USER_MEMBER));
		try {
			foreach ($groups as $group) {
				try {
					$circles->addMember($circleId, $circles->getFederatedUser($group, self::GROUP_MEMBER));
				} catch (Throwable) {
					continue;
				}
			}
		} finally {
			$circles->stopSession();
		}
	}//end addGroups()
}//end class
