<?php

/**
 * PollsProvider — exposes NC Polls linked to an OR object via the
 * IntegrationProvider contract.
 *
 * Tier-2: backed by the `openregister_poll_links` table via
 * {@see PollLinkMapper}. Replaces the original title-marker convention
 * (`[or:{uuid}]` embedded in poll title) with a proper persistence
 * layer so links survive title edits and don't pollute Polls' UX.
 *
 * Reads each linked poll's current title/type/expire/voter count/option
 * tallies directly from `oc_polls_polls` / `oc_polls_options` /
 * `oc_polls_votes` (Phase B-3 session-workaround pattern: Polls' own
 * services need Polls' UserSession populated, which it isn't when OR
 * serves the sub-resource).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/integration-polls/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- self-documenting IntegrationProvider metadata getters mirror the contract in the interface.

use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use Throwable;

class PollsProvider extends AbstractIntegrationProvider {

	use NcAppLinkRowsProvider;
	use FirstRowQuery;

	private const REQUIRED_APP = 'polls';

	/**
	 * NC DB connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * NC app manager.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * Localisation.
	 *
	 * @var IL10N
	 */
	private IL10N $l10n;

	/**
	 * Constructor.
	 *
	 * @param LeafProviderPlatform    $platform       The DB connection, app manager and localisation.
	 * @param PollLinkMapperInterface $pollLinkMapper Poll-link mapper (Tier-2 link table).
	 * @param IUserSession            $userSession    The current user's session.
	 */
	public function __construct(
		LeafProviderPlatform $platform,
		private PollLinkMapperInterface $pollLinkMapper,
		private IUserSession $userSession,
	) {
		$this->db = $platform->db;
		$this->appManager = $platform->appManager;
		$this->l10n = $platform->l10n;
	}//end __construct()

	public function getId(): string {
		return 'polls';
	}//end getId()

	public function getLabel(): string {
		return $this->l10n->t('Polls');
	}//end getLabel()

	public function getIcon(): string {
		return 'Poll';
	}//end getIcon()

	public function getGroup(): ?string {
		return 'workflow';
	}//end getGroup()

	/**
	 * The link mapper this provider reads.
	 *
	 * @return PollLinkMapperInterface
	 */
	protected function linkMapper(): PollLinkMapperInterface {
		return $this->pollLinkMapper;
	}//end linkMapper()

	/**
	 * One poll link, hydrated with the live poll row, its options with yes-vote
	 * tallies and its voter count; the link's cached title and type fill gaps.
	 *
	 * @param PollLink $link Link row from the mapper.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-polls/spec.md
	 */
	protected function rowFromLink(PollLink $link): array {
		$pollId = (int)$link->getPollId();
		$pollRow = $this->fetchPollRow(pollId: $pollId);
		$expire = (int)($pollRow['expire'] ?? 0);
		$options = $this->fetchOptionsWithCounts(pollId: $pollId);

		return $this->pollFields(link: $link, pollRow: $pollRow) + [
			'deadline' => $this->deadlineOf(expire: $expire),
			'closed' => ($expire > 0 && $expire <= time()),
			'voterCount' => $this->fetchVoterCount(pollId: $pollId),
			'options' => $options,
			'linkId' => $link->getId(),
		];
	}//end rowFromLink()

	/**
	 * The poll's id, title, description, type and url: the live row
	 * first, the link's cached columns second.
	 *
	 * @param PollLink                 $link    Link row.
	 * @param array<string,mixed>|null $pollRow Live `oc_polls_polls` row, if any.
	 *
	 * @return array<string,mixed>
	 */
	private function pollFields(PollLink $link, ?array $pollRow): array {
		$pollId = (int)$link->getPollId();

		return [
			'id' => (string)$pollId,
			'pollId' => $pollId,
			'title' => (string)($pollRow['title'] ?? $link->getPollTitle() ?? ''),
			'description' => (string)($pollRow['description'] ?? ''),
			'type' => (string)($pollRow['type'] ?? $link->getPollType() ?? ''),
			'url' => '/index.php/apps/polls/vote/' . $pollId,
		];
	}//end pollFields()

	/**
	 * The deadline of a poll: its expiry, or null when it never expires.
	 *
	 * @param int $expire Unix expiry, 0 for none.
	 *
	 * @return int|null
	 */
	private function deadlineOf(int $expire): ?int {
		if ($expire > 0) {
			return $expire;
		}

		return null;
	}//end deadlineOf()

	/**
	 * Fetch a poll row from `oc_polls_polls`.
	 *
	 * @param int $pollId Poll id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchPollRow(int $pollId): ?array {
		return $this->firstRowOf(
			query: function () use ($pollId): IQueryBuilder {
				$qb = $this->db->getQueryBuilder();
				return $qb->select('id', 'title', 'description', 'type', 'expire')
					->from('polls_polls')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			}
		);
	}//end fetchPollRow()

	/**
	 * Poll options with their yes-vote tallies.
	 *
	 * Returns `[{id, text, votes}, ...]`, ordered by stored option order.
	 * Returns an empty array when the options cannot be read.
	 *
	 * @param int $pollId Poll primary key.
	 *
	 * @return array<int,array{id:int,text:string,votes:int}>
	 */
	private function fetchOptionsWithCounts(int $pollId): array {
		$out = [];
		foreach ($this->fetchOptionRows(pollId: $pollId) as $opt) {
			$out[] = [
				'id' => (int)($opt['id'] ?? 0),
				'text' => (string)($opt['poll_option_text'] ?? ''),
				'votes' => $this->countYesVotes(pollId: $pollId, hash: (string)($opt['poll_option_hash'] ?? '')),
			];
		}

		return $out;
	}//end fetchOptionsWithCounts()

	/**
	 * The poll's non-deleted option rows in stored order; none on any DB failure.
	 *
	 * @param int $pollId Poll primary key.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchOptionRows(int $pollId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'poll_option_text', 'poll_option_hash')
				->from('polls_options')
				->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('order', 'ASC');
			return $qb->executeQuery()->fetchAll();
		} catch (Throwable $e) {
			return [];
		}
	}//end fetchOptionRows()

	/**
	 * Non-deleted yes votes on one option; 0 on any DB failure.
	 *
	 * @param int    $pollId Poll primary key.
	 * @param string $hash   Option hash.
	 *
	 * @return int
	 */
	private function countYesVotes(int $pollId, string $hash): int {
		try {
			$query = $this->db->getQueryBuilder();
			$query->select($query->func()->count('*', 'cnt'))
				->from('polls_votes')
				->where($query->expr()->eq('poll_id', $query->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
				->andWhere($query->expr()->eq('vote_option_hash', $query->createNamedParameter($hash)))
				->andWhere($query->expr()->eq('vote_answer', $query->createNamedParameter('yes')))
				->andWhere($query->expr()->eq('deleted', $query->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			return (int)$query->executeQuery()->fetchOne();
		} catch (Throwable $e) {
			return 0;
		}
	}//end countYesVotes()

	/**
	 * Distinct user count that has cast at least one non-deleted vote.
	 *
	 * @param int $pollId Poll primary key.
	 *
	 * @return int
	 */
	private function fetchVoterCount(int $pollId): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('user_id')
				->from('polls_votes')
				->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
			$rows = $qb->executeQuery()->fetchAll();
			return count($rows);
		} catch (Throwable $e) {
			return 0;
		}
	}//end fetchVoterCount()

	/**
	 * Provider health descriptor (enabled/disabled echo).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude Static enabled/disabled descriptor echoing IAppManager::isInstalled — no standalone health
	 *              behaviour; the health/OCS contract is owned by pluggable-integration-registry task-2.
	 */
	public function health(): array {
		return $this->installedHealth(available: $this->appManager->isInstalled(self::REQUIRED_APP) === true, missing: 'NC Polls app is not installed');
	}//end health()
}//end class
