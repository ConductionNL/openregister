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
use OCA\OpenRegister\Kernel\Contracts\ContactDetails;
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
			contact: new ContactDetails(email: $primaryEmail, name: $fullName, organization: $organizationString)
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
		$countText = $this->countText(counts: $counts);
		$searchUrl = $this->searchUrl(email: $run->primaryEmail);

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
	 * The human-readable related-object counts, such as "2 Cases, 1 Document".
	 *
	 * @param array<string, int> $counts Count per schema title.
	 *
	 * @return string The count text.
	 */
	private function countText(array $counts): string {
		$parts = [];
		foreach ($counts as $schemaTitle => $count) {
			$parts[] = $count . ' ' . $schemaTitle;
		}

		return implode(', ', $parts);
	}//end countText()

	/**
	 * The OpenRegister search URL filtered by an email address.
	 *
	 * @param string $email The email address.
	 *
	 * @return string The search URL.
	 */
	private function searchUrl(string $email): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'openregister.dashboard.index'
		) . '#/search?_search=' . urlencode($email);
	}//end searchUrl()

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

		$icon = $this->entityIcon(registerId: $registerId, schemaId: $schemaId);

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
	 * The icon a consuming app registered for a register and schema, or OpenRegister's own.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId   The schema id.
	 *
	 * @return string The icon URL.
	 */
	private function entityIcon(int $registerId, int $schemaId): string {
		$resolvedIcon = $this->deepLinkRegistry->resolveIcon($registerId, $schemaId);

		return $resolvedIcon ?? $this->urlGenerator->imagePath('openregister', 'app-dark.svg');
	}//end entityIcon()

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
		$contactContext = $this->contactContextOf(run: $run);

		$url = $this->deepLinkRegistry->resolveUrl(
			(int)($match['register']['id'] ?? 0),
			(int)($match['schema']['id'] ?? 0),
			array_merge($match, ['uuid' => $uuid]),
			$contactContext
		);

		if ($url === null) {
			// Fallback to OpenRegister's generic object detail route.
			$url = $this->objectDetailUrl(uuid: $uuid);
		}

		return $url;
	}//end entityUrl()

	/**
	 * The contact placeholders a deep link may use: its UID, email and name.
	 *
	 * @param ContactMenuRun $run The contact being processed.
	 *
	 * @return array{contactId: mixed, contactEmail: string, contactName: string}
	 */
	private function contactContextOf(ContactMenuRun $run): array {
		return [
			'contactId' => $run->entry->getProperty('UID') ?? '',
			'contactEmail' => $run->primaryEmail,
			'contactName' => $run->fullName,
		];
	}//end contactContextOf()

	/**
	 * OpenRegister's own object detail URL.
	 *
	 * @param mixed $uuid The object uuid.
	 *
	 * @return string The URL.
	 */
	private function objectDetailUrl(mixed $uuid): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'openregister.dashboard.index'
		) . '#/objects/' . urlencode($uuid);
	}//end objectDetailUrl()
}//end class
