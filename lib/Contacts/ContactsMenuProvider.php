<?php

/**
 * OpenRegister ContactsMenuProvider
 *
 * Nextcloud Contacts Menu provider that bridges Contacts/CardDAV
 * with OpenRegister entity data.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contacts
 * @package  OCA\OpenRegister\Contacts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Contacts;

use OCA\OpenRegister\Contacts\Support\ContactsMenuPlatform;
use OCA\OpenRegister\Contacts\Support\ContactsMenuSources;
use OCA\OpenRegister\Kernel\Contracts\ContactMatcherInterface;
use OCA\OpenRegister\Service\DeepLinkResolverInterface;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Contacts menu provider that injects OpenRegister entity actions.
 *
 * When a user clicks on a contact in Nextcloud's contacts menu, this provider:
 * 1. Extracts email, name, and organization from the contact
 * 2. Matches against OpenRegister entities
 * 3. Injects action links and a count badge into the contacts popup
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ContactsMenuProvider implements IProvider {

	/**
	 * The contact matching service.
	 *
	 * @var ContactMatcherInterface
	 */
	private readonly ContactMatcherInterface $matchingService;

	/**
	 * The deep link registry service.
	 *
	 * @var DeepLinkResolverInterface
	 */
	private readonly DeepLinkResolverInterface $deepLinkRegistry;

	/**
	 * The action factory for creating menu actions.
	 *
	 * @var IActionFactory
	 */
	private readonly IActionFactory $actionFactory;

	/**
	 * The URL generator.
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urlGenerator;

	/**
	 * The localization service.
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * Logger for debugging.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor for ContactsMenuProvider.
	 *
	 * @param ContactsMenuSources  $sources  Contact matcher and deep-link resolver.
	 * @param ContactsMenuPlatform $platform Action factory, URL generator, translations and logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		ContactsMenuSources $sources,
		ContactsMenuPlatform $platform,
	) {
		$this->matchingService = $sources->matchingService;
		$this->deepLinkRegistry = $sources->deepLinkRegistry;
		$this->actionFactory = $platform->actionFactory;
		$this->urlGenerator = $platform->urlGenerator;
		$this->l10n = $platform->l10n;
		$this->logger = $platform->logger;
	}//end __construct()

	/**
	 * Process a contact entry and inject OpenRegister actions.
	 *
	 * @param IEntry $entry The contact entry to process
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function process(IEntry $entry): void {
		try {
			$this->doProcess(entry: $entry);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[ContactsMenu] Error processing contact entry: {error}',
				[
					'error' => $e->getMessage(),
					'exception' => $e,
				]
			);
		}
	}//end process()

	/**
	 * Internal processing logic (separated for testability).
	 *
	 * @param IEntry $entry The contact entry
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function doProcess(IEntry $entry): void {
		// Extract contact metadata.
		$emails = $entry->getEMailAddresses();
		$primaryEmail = $emails[0] ?? '';
		$fullName = $entry->getFullName();
		$organization = $entry->getProperty('ORG');

		if (empty($primaryEmail) === true && empty($fullName) === true) {
			return;
		}

		// Match contact against OpenRegister entities.
		$organizationString = null;
		if (is_string($organization) === true) {
			$organizationString = $organization;
		}

		$matches = $this->matchingService->matchContact(
			$primaryEmail,
			$fullName,
			$organizationString
		);

		if (empty($matches) === true) {
			return;
		}

		$run = new ContactMenuRun(
			entry: $entry,
			matches: $matches,
			primaryEmail: $primaryEmail,
			fullName: $fullName
		);

		// Inject count badge (highest priority = renders first).
		$this->injectCountBadge(run: $run);

		// Inject individual entity actions.
		foreach ($matches as $match) {
			$this->injectEntityAction(run: $run, match: $match);
		}
	}//end doProcess()

	/**
	 * Inject a count badge summary action.
	 *
	 * @param ContactMenuRun $run The contact, its primary email (for the search link) and its matches
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function injectCountBadge(ContactMenuRun $run): void {
		$counts = $this->matchingService->getRelatedObjectCounts($run->matches);

		// Build human-readable count string.
		$parts = [];
		foreach ($counts as $schemaTitle => $count) {
			$parts[] = $count . ' ' . $schemaTitle;
		}

		$countText = implode(', ', $parts);

		// Build search URL filtered by email.
		$searchUrl = $this->urlGenerator->linkToRouteAbsolute(
			'openregister.dashboard.index'
		) . '#/search?_search=' . urlencode($run->primaryEmail);

		$action = $this->actionFactory->newLinkAction(
			$this->urlGenerator->imagePath('openregister', 'app-dark.svg'),
			$countText,
			$searchUrl,
			'openregister'
		);
		$action->setPriority(0);

		$run->entry->addAction($action);
	}//end injectCountBadge()

	/**
	 * Inject the action for one matched entity.
	 *
	 * @param ContactMenuRun $run   The contact the action is added to
	 * @param array          $match The matched entity
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function injectEntityAction(ContactMenuRun $run, array $match): void {
		$registerId = (int)($match['register']['id'] ?? 0);
		$schemaId = (int)($match['schema']['id'] ?? 0);
		$url = $this->entityUrl(run: $run, match: $match);

		$resolvedIcon = $this->deepLinkRegistry->resolveIcon($registerId, $schemaId);
		$icon = $resolvedIcon ?? $this->urlGenerator->imagePath('openregister', 'app-dark.svg');

		$label = $this->l10n->t('View in OpenRegister') . ' (' . ($match['title'] ?? 'Unknown') . ')';

		$action = $this->actionFactory->newLinkAction(
			$icon,
			$label,
			$url,
			'openregister'
		);
		$action->setPriority(10);

		$run->entry->addAction($action);
	}//end injectEntityAction()

	/**
	 * Resolve the URL of one matched entity: the app's deep link, else OpenRegister's object detail.
	 *
	 * @param ContactMenuRun $run   The contact (its context is handed to the deep-link resolver)
	 * @param array          $match The matched entity
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function entityUrl(ContactMenuRun $run, array $match): string {
		$uuid = $match['uuid'] ?? '';
		$contactContext = [
			'contactId' => $run->entry->getProperty('UID') ?? '',
			'contactEmail' => $run->primaryEmail,
			'contactName' => $run->fullName,
		];

		$url = $this->deepLinkRegistry->resolveUrl(
			(int)($match['register']['id'] ?? 0),
			(int)($match['schema']['id'] ?? 0),
			array_merge($match, ['uuid' => $uuid]),
			$contactContext
		);

		if ($url === null) {
			// Fallback to OpenRegister's generic object detail route.
			$url = $this->urlGenerator->linkToRouteAbsolute(
				'openregister.dashboard.index'
			) . '#/objects/' . urlencode($uuid);
		}

		return $url;
	}//end entityUrl()
}//end class
