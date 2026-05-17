<?php

/**
 * PollsProvider — exposes NC Polls linked to an OR object via the
 * IntegrationProvider contract.
 *
 * `link-table` storage (a future `openregister_poll_links` pairs
 * object ↔ poll); the wrapping PollsService lands in a follow-up —
 * this provider registers the registry surface today.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-polls/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

class PollsProvider extends AbstractIntegrationProvider
{

    private const REQUIRED_APP = 'polls';
    private const TITLE_TAG    = '[or:';

    public function __construct(
        private ContainerInterface $container,
        private IAppManager $appManager,
        private IUserSession $userSession,
        private IL10N $l10n,
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'polls';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Polls');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'Poll';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return self::REQUIRED_APP;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        return $this->appManager->isInstalled(self::REQUIRED_APP);
    }//end isEnabled()

    /**
     * List polls linked to an OR object.
     *
     * Linking convention: polls whose title contains the marker
     * `[or:{objectUuid}]`. The provider asks Polls' PollService for
     * the current user's polls, filters by marker, and normalises the
     * rows into the registry's leaf row shape.
     *
     * @param string              $register Register slug or numeric id (unused).
     * @param string              $schema   Schema slug or numeric id (unused).
     * @param string              $objectId Object uuid.
     * @param array<string,mixed> $filters  Optional filters (unused).
     *
     * @return array<int,array<string,mixed>>
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $marker = self::TITLE_TAG.$objectId.']';

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        // Query the polls table directly. Polls' PollMapper::buildQuery
        // depends on Polls' own UserSession service for joins / detail
        // expansion; when OR's controller serves the sub-resource with
        // Basic auth the Polls session isn't populated, so listByOwner
        // returns Poll entities with empty title/description fields.
        // Going through the raw DB row sidesteps that and is sufficient
        // for the marker-based link filter.
        try {
            $db    = $this->container->get('OCP\\IDBConnection');
            $qb    = $db->getQueryBuilder();
            $qb->select('*')->from('polls_polls')->where(
                $qb->expr()->eq('owner', $qb->createNamedParameter($user->getUID()))
            );
            $rows = $qb->executeQuery()->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $title = (string) ($row['title'] ?? '');
            if (str_contains($title, $marker) === false) {
                continue;
            }
            $id  = (string) ($row['id'] ?? '');
            $out[] = [
                'id'          => $id,
                'title'       => $title,
                'description' => (string) ($row['description'] ?? ''),
                'type'        => (string) ($row['type'] ?? ''),
                'url'         => '/index.php/apps/polls/vote/'.$id,
            ];
        }

        return $out;
    }//end list()

    public function health(): array
    {
        $installed = $this->appManager->isInstalled(self::REQUIRED_APP);
        return [
            'status'     => $installed === true ? 'ok' : 'unavailable',
            'authStatus' => 'configured',
            'message'    => $installed === true ? null : 'NC Polls app is not installed',
        ];
    }//end health()
}//end class
