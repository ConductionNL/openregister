<?php

/**
 * OpenRegister ContactsMenuProvider
 *
 * Nextcloud Contacts Menu provider that bridges Contacts/CardDAV
 * with OpenRegister entity data.
 *
 * @category Contacts
 * @package  OCA\OpenRegister\Contacts
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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

use OCA\OpenRegister\Service\ContactMatchingService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
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
class ContactsMenuProvider implements IProvider
{

    /**
     * The contact matching service.
     *
     * @var ContactMatchingService
     */
    private readonly ContactMatchingService $matchingService;

    /**
     * The deep link registry service.
     *
     * @var DeepLinkRegistryService
     */
    private readonly DeepLinkRegistryService $deepLinkRegistry;

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
     * @param ContactMatchingService  $matchingService  The contact matching service
     * @param DeepLinkRegistryService $deepLinkRegistry The deep link registry
     * @param IActionFactory          $actionFactory    The action factory
     * @param IURLGenerator           $urlGenerator     The URL generator
     * @param IL10N                   $l10n             The localization service
     * @param LoggerInterface         $logger           The logger
     *
     * @return void
     */
    public function __construct(
        ContactMatchingService $matchingService,
        DeepLinkRegistryService $deepLinkRegistry,
        IActionFactory $actionFactory,
        IURLGenerator $urlGenerator,
        IL10N $l10n,
        LoggerInterface $logger
    ) {
        $this->matchingService  = $matchingService;
        $this->deepLinkRegistry = $deepLinkRegistry;
        $this->actionFactory    = $actionFactory;
        $this->urlGenerator     = $urlGenerator;
        $this->l10n             = $l10n;
        $this->logger           = $logger;
    }//end __construct()

    /**
     * Process a contact entry and inject OpenRegister actions.
     *
     * @param IEntry $entry The contact entry to process
     *
     * @return void
     */
    public function process(IEntry $entry): void
    {
        try {
            $this->doProcess($entry);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[ContactsMenu] Error processing contact entry: {error}',
                [
                    'error'     => $e->getMessage(),
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
     */
    private function doProcess(IEntry $entry): void
    {
        // Extract contact metadata.
        $emails       = $entry->getEMailAddresses();
        $primaryEmail = $emails[0] ?? '';
        $fullName     = $entry->getFullName();
        $organization = $entry->getProperty('ORG');

        if (empty($primaryEmail) === true && empty($fullName) === true) {
            return;
        }

        // Match contact against OpenRegister entities.
        $matches = $this->matchingService->matchContact(
            $primaryEmail,
            $fullName,
            is_string($organization) === true ? $organization : null
        );

        if (empty($matches) === true) {
            return;
        }

        // Inject count badge (highest priority = renders first).
        $this->injectCountBadge($entry, $matches, $primaryEmail);

        // Inject individual entity actions.
        $this->injectEntityActions($entry, $matches, $primaryEmail, $fullName);
    }//end doProcess()

    /**
     * Inject a count badge summary action.
     *
     * @param IEntry $entry        The contact entry
     * @param array  $matches      The matched entities
     * @param string $primaryEmail The primary email for the search link
     *
     * @return void
     */
    private function injectCountBadge(IEntry $entry, array $matches, string $primaryEmail): void
    {
        $counts = $this->matchingService->getRelatedObjectCounts($matches);

        // Build human-readable count string.
        $parts = [];
        foreach ($counts as $schemaTitle => $count) {
            $parts[] = $count . ' ' . $schemaTitle;
        }

        $countText = implode(', ', $parts);

        // Build search URL filtered by email.
        $searchUrl = $this->urlGenerator->linkToRouteAbsolute(
            'openregister.dashboard.index'
        ) . '#/search?_search=' . urlencode($primaryEmail);

        $action = $this->actionFactory->newLinkAction(
            $this->urlGenerator->imagePath('openregister', 'app-dark.svg'),
            $countText,
            $searchUrl,
            'openregister'
        );
        $action->setPriority(0);

        $entry->addAction($action);
    }//end injectCountBadge()

    /**
     * Inject individual entity actions.
     *
     * @param IEntry      $entry        The contact entry
     * @param array       $matches      The matched entities
     * @param string      $primaryEmail The primary email
     * @param string|null $fullName     The contact full name
     *
     * @return void
     */
    private function injectEntityActions(
        IEntry $entry,
        array $matches,
        string $primaryEmail,
        ?string $fullName
    ): void {
        foreach ($matches as $match) {
            $registerId = (int) ($match['register']['id'] ?? 0);
            $schemaId   = (int) ($match['schema']['id'] ?? 0);
            $uuid       = $match['uuid'] ?? '';

            // Try deep link resolution first.
            $contactContext = [
                'contactId'    => $entry->getProperty('UID') ?? '',
                'contactEmail' => $primaryEmail,
                'contactName'  => $fullName ?? '',
            ];

            $url = $this->deepLinkRegistry->resolveUrl(
                $registerId,
                $schemaId,
                array_merge($match, ['uuid' => $uuid]),
                $contactContext
            );

            if ($url === null) {
                // Fallback to OpenRegister's generic object detail route.
                $url = $this->urlGenerator->linkToRouteAbsolute(
                    'openregister.dashboard.index'
                ) . '#/objects/' . urlencode($uuid);
            }

            $icon = $this->deepLinkRegistry->resolveIcon($registerId, $schemaId)
                ?? $this->urlGenerator->imagePath('openregister', 'app-dark.svg');

            $label = $this->l10n->t('View in OpenRegister')
                . ' (' . ($match['title'] ?? 'Unknown') . ')';

            $action = $this->actionFactory->newLinkAction(
                $icon,
                $label,
                $url,
                'openregister'
            );
            $action->setPriority(10);

            $entry->addAction($action);
        }
    }//end injectEntityActions()
}//end class
