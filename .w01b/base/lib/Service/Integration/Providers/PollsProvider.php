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

use OCA\OpenRegister\Db\PollLinkMapper;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use Throwable;

class PollsProvider extends AbstractIntegrationProvider {

	private const REQUIRED_APP = 'polls';

	public function __construct(
		private PollLinkMapper $pollLinkMapper,
		private IDBConnection $db,
		private IAppManager $appManager,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
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

	public function getRequiredApp(): ?string {
		return self::REQUIRED_APP;
	}//end getRequiredApp()

	public function getStorageStrategy(): string {
		return 'link-table';
	}//end getStorageStrategy()

	public function isEnabled(): bool {
		return $this->appManager->isInstalled(self::REQUIRED_APP);
	}//end isEnabled()

	/**
	 * List polls linked to an OR object.
	 *
	 * Reads link rows from `openregister_poll_links`, then hydrates
	 * each row with current title/type/deadline + the per-option vote
	 * tallies (used by CnPollsTab to render progress bars). Returns
	 * an empty array when Polls is uninstalled.
	 *
	 * @param string $register Register slug or numeric id (unused).
	 * @param string $schema Schema slug or numeric id (unused).
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Optional filters (unused).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/integration-polls/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) AbstractIntegrationProvider::list() mandates
	 * (register, schema, objectId, filters); $register and $schema are unused because Polls links
	 * are stored per-object-uuid only, but the interface signature must match.
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		if ($this->isEnabled() === false) {
			return [];
		}

		$links = $this->pollLinkMapper->findByObjectUuid($objectId);
		if ($links === []) {
			return [];
		}

		$out = [];
		foreach ($links as $link) {
			$pollId = (int)$link->getPollId();
			$pollRow = $this->fetchPollRow(pollId: $pollId);

			$title = (string)($pollRow['title'] ?? $link->getPollTitle() ?? '');
			$description = (string)($pollRow['description'] ?? '');
			$type = (string)($pollRow['type'] ?? $link->getPollType() ?? '');
			$expire = (int)($pollRow['expire'] ?? 0);

			$options = $this->fetchOptionsWithCounts(pollId: $pollId);
			$voters = $this->fetchVoterCount(pollId: $pollId);
			$deadline = null;
			if ($expire > 0) {
				$deadline = $expire;
			}

			$out[] = [
				'id' => (string)$pollId,
				'pollId' => $pollId,
				'title' => $title,
				'description' => $description,
				'type' => $type,
				'url' => '/index.php/apps/polls/vote/' . $pollId,
				'deadline' => $deadline,
				'closed' => ($expire > 0 && $expire <= time()),
				'voterCount' => $voters,
				'options' => $options,
				'linkId' => $link->getId(),
			];
		}//end foreach

		return $out;
	}//end list()

	/**
	 * Fetch a poll row from `oc_polls_polls`.
	 *
	 * @param int $pollId Poll id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchPollRow(int $pollId): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'title', 'description', 'type', 'expire')
				->from('polls_polls')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}

			return $row;
		} catch (Throwable $e) {
			return null;
		}
	}//end fetchPollRow()

	/**
	 * Fetch poll options with their yes-vote tallies.
	 *
	 * Returns `[{id, text, votes}, ...]`, ordered by stored option
	 * order. Vote counts only include `vote_answer = 'yes'` /
	 * non-deleted rows. Returns an empty array on any DB failure.
	 *
	 * @param int $pollId Poll primary key.
	 *
	 * @return array<int,array{id:int,text:string,votes:int}>
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable) $vq is the query-builder variable for the inner vote-count
	 * query; it is intentionally distinct from $qb (the outer options query) to make the two nested
	 * queries readable in sequence.
	 */
	private function fetchOptionsWithCounts(int $pollId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'poll_option_text', 'poll_option_hash')
				->from('polls_options')
				->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
				->orderBy('order', 'ASC');
			$optionRows = $qb->executeQuery()->fetchAll();
		} catch (Throwable $e) {
			return [];
		}

		$out = [];
		foreach ($optionRows as $opt) {
			$hash = (string)($opt['poll_option_hash'] ?? '');
			$votes = 0;
			try {
				$vq = $this->db->getQueryBuilder();
				$vq->select($vq->func()->count('*', 'cnt'))
					->from('polls_votes')
					->where($vq->expr()->eq('poll_id', $vq->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
					->andWhere($vq->expr()->eq('vote_option_hash', $vq->createNamedParameter($hash)))
					->andWhere($vq->expr()->eq('vote_answer', $vq->createNamedParameter('yes')))
					->andWhere($vq->expr()->eq('deleted', $vq->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
				$fetchedVotes = $vq->executeQuery()->fetchOne();
				$votes = 0;
				if ($fetchedVotes !== false) {
					$votes = (int)$fetchedVotes;
				}
			} catch (Throwable $e) {
				$votes = 0;
			}

			$out[] = [
				'id' => (int)($opt['id'] ?? 0),
				'text' => (string)($opt['poll_option_text'] ?? ''),
				'votes' => $votes,
			];
		}//end foreach

		return $out;
	}//end fetchOptionsWithCounts()

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
		$installed = $this->appManager->isInstalled(self::REQUIRED_APP);

		$status = 'unavailable';
		if ($installed === true) {
			$status = 'ok';
		}

		$message = 'NC Polls app is not installed';
		if ($installed === true) {
			$message = null;
		}

		return [
			'status' => $status,
			'authStatus' => 'configured',
			'message' => $message,
		];
	}//end health()
}//end class
