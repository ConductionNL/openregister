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
            $db = $this->container->get('OCP\\IDBConnection');
            $qb = $db->getQueryBuilder();
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

            $id      = (string) ($row['id'] ?? '');
            $pollId  = (int) ($row['id'] ?? 0);
            $expire  = (int) ($row['expire'] ?? 0);
            $options = $this->fetchOptionsWithCounts($db, $pollId);
            $voters  = $this->fetchVoterCount($db, $pollId);

            $out[] = [
                'id'          => $id,
                'title'       => $title,
                'description' => (string) ($row['description'] ?? ''),
                'type'        => (string) ($row['type'] ?? ''),
                'url'         => '/index.php/apps/polls/vote/'.$id,
                'deadline'    => $expire > 0 ? $expire : null,
                'closed'      => ($expire > 0 && $expire <= time()),
                'voterCount'  => $voters,
                'options'     => $options,
            ];
        }

        return $out;
    }//end list()

    /**
     * Fetch poll options with their yes-vote tallies.
     *
     * Returns a list of `{id, text, votes}` rows, ordered by the poll's
     * stored option order. Vote counts only include rows where
     * `vote_answer = 'yes'` and the option/vote are not soft-deleted —
     * mirrors Polls' own tally surface. Returns an empty array on any
     * DB failure to keep the leaf row degradation-safe.
     *
     * @param \OCP\IDBConnection $db     OR's lazy-resolved DB handle.
     * @param int                $pollId Poll primary key.
     *
     * @return array<int,array{id:int,text:string,votes:int}>
     */
    private function fetchOptionsWithCounts(\OCP\IDBConnection $db, int $pollId): array
    {
        try {
            $qb = $db->getQueryBuilder();
            $qb->select('id', 'poll_option_text', 'poll_option_hash')
                ->from('polls_options')
                ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->orderBy('order', 'ASC');
            $optionRows = $qb->executeQuery()->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($optionRows as $opt) {
            $hash = (string) ($opt['poll_option_hash'] ?? '');
            $votes = 0;
            try {
                $vq = $db->getQueryBuilder();
                $vq->select($vq->func()->count('*', 'cnt'))
                    ->from('polls_votes')
                    ->where($vq->expr()->eq('poll_id', $vq->createNamedParameter($pollId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($vq->expr()->eq('vote_option_hash', $vq->createNamedParameter($hash)))
                    ->andWhere($vq->expr()->eq('vote_answer', $vq->createNamedParameter('yes')))
                    ->andWhere($vq->expr()->eq('deleted', $vq->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
                $votes = (int) ($vq->executeQuery()->fetchOne() ?: 0);
            } catch (Throwable $e) {
                $votes = 0;
            }

            $out[] = [
                'id'    => (int) ($opt['id'] ?? 0),
                'text'  => (string) ($opt['poll_option_text'] ?? ''),
                'votes' => $votes,
            ];
        }

        return $out;
    }//end fetchOptionsWithCounts()

    /**
     * Distinct user count that has cast at least one non-deleted vote.
     *
     * @param \OCP\IDBConnection $db     OR's lazy-resolved DB handle.
     * @param int                $pollId Poll primary key.
     *
     * @return int
     */
    private function fetchVoterCount(\OCP\IDBConnection $db, int $pollId): int
    {
        try {
            $qb = $db->getQueryBuilder();
            $qb->selectDistinct('user_id')
                ->from('polls_votes')
                ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $rows = $qb->executeQuery()->fetchAll();
            return count($rows);
        } catch (Throwable $e) {
            return 0;
        }
    }//end fetchVoterCount()

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
