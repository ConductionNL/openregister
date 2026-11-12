<?php

/**
 * PollLinkService — Tier-2 polls integration service.
 *
 * Composes the {@see PollLinkMapper} with direct queries into NC Polls'
 * own tables (`oc_polls_polls`, `oc_polls_options`, `oc_polls_votes`)
 * to provide the picker + inline-create UX surface area:
 *
 *   - linkPoll(uuid, registerId, schemaId, pollId)          — link existing poll
 *   - createAndLinkPoll(target, draft)                       — create + link new
 *   - unlinkPoll(uuid, pollId)
 *   - getLinkedPolls(uuid)                                  — list, enriched
 *   - getAvailablePolls(search?)                            — picker source
 *
 * Polls' own `PollService::list` requires Polls' UserSession to be
 * populated (which it isn't when OR serves the sub-resource), so this
 * service uses the same DB-direct pattern as Phase B-3 PollsProvider:
 * raw queries against `oc_polls_*` tables. When Polls is unavailable,
 * `getLinkedPolls` still returns the persisted link row so historical
 * references survive even after Polls is uninstalled.
 *
 * The `createAndLinkPoll` path INSERTs directly into `oc_polls_polls` +
 * `oc_polls_options` because Polls' `PollService::add` also depends on
 * Polls' UserSession + `requestAccessControl`, and the create flow runs
 * inside an OR controller where that session isn't bootstrapped.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes PollLinkMapper, IDBConnection,
 *   IAppManager, IUserSession, and LoggerInterface; each is a distinct orchestration
 *   concern for direct-DB poll creation and user-session handling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Direct SQL inserts into oc_polls_polls
 *   and oc_polls_options are required because Polls' PollService depends on its own
 *   UserSession which is not bootstrapped in OR controller context.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapperInterface;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PollLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper + DB
 *     + IAppManager + IUserSession + logger. Each dependency is
 *     required.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Direct table writes
 *     for the create+link path inflate the count; the alternative
 *     (re-implementing Polls' UserSession) would be worse.
 */
class PollLinkService implements PollLinkServiceInterface {
	/**
	 * Fixed `polls_polls` column values for a created poll: [value, parameter type].
	 */
	private const POLL_DEFAULTS = [
		'deleted' => [0, IQueryBuilder::PARAM_INT],
		'access' => ['private', IQueryBuilder::PARAM_STR],
		'anonymous' => [0, IQueryBuilder::PARAM_INT],
		'allow_maybe' => [1, IQueryBuilder::PARAM_INT],
		'allow_proposals' => ['disallow', IQueryBuilder::PARAM_STR],
		'show_results' => ['always', IQueryBuilder::PARAM_STR],
		'admin_access' => [0, IQueryBuilder::PARAM_INT],
		'allow_comment' => [1, IQueryBuilder::PARAM_INT],
		'hide_booked_up' => [1, IQueryBuilder::PARAM_INT],
		'use_no' => [1, IQueryBuilder::PARAM_INT],
		'voting_variant' => ['simple', IQueryBuilder::PARAM_STR],
	];

	/**
	 * Constructor.
	 *
	 * @param PollLinkMapperInterface $pollLinkMapper Persistence for link rows.
	 * @param IDBConnection $db Database connection.
	 * @param LinkServiceRuntime $runtime Whether the app is enabled, the current user, and the logger.
	 */
	public function __construct(
		private readonly PollLinkMapperInterface $pollLinkMapper,
		private readonly IDBConnection $db,
		private readonly LinkServiceRuntime $runtime,
	) {
	}//end __construct()

	/**
	 * Whether NC Polls is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isPollsAvailable(): bool {
		return $this->runtime->appManager()->isEnabledForUser('polls');
	}//end isPollsAvailable()

	/**
	 * Link an existing Polls poll to an OR object.
	 *
	 * Idempotent: a duplicate link raises a 409 Exception (callers
	 * should surface as HTTP 409 to the UI).
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param int $pollId Polls poll id.
	 *
	 * @return PollLink The persisted link row.
	 *
	 * @throws Exception On missing user, missing poll (404), duplicate (409).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkPoll(LinkTarget $target, int $pollId): PollLink {
		$user = $this->runtime->userSession()->getUser() ?? throw new Exception('No user logged in');
		if ($this->pollLinkMapper->findByObjectAndPoll($target->objectUuid, $pollId) !== null) {
			throw new Exception('Poll already linked to this object', 409);
		}

		if ($this->isPollsAvailable() === false) {
			throw new Exception('Polls is not available', 503);
		}

		$pollRow = $this->fetchPollRow(pollId: $pollId) ?? throw new Exception('Poll not found', 404);
		$stats   = $this->liveStats(pollId: $pollId, pollRow: $pollRow);
		$title   = (string)($pollRow['title'] ?? '');
		$link    = $this->newPollLink(poll: new PollIdentity(id: $pollId, title: $title, type: (string)($pollRow['type'] ?? '')), stats: $stats);
		$target->stamp(link: $link, linkedBy: $user->getUID());

		return $this->pollLinkMapper->insert($link);
	}//end linkPoll()

	/**
	 * The live deadline, closed flag and tallies of a poll row.
	 *
	 * @param int                 $pollId  Poll id.
	 * @param array<string,mixed> $pollRow The `polls_polls` row.
	 *
	 * @return array{deadline:?DateTime,closed:bool,voterCount:int,optionCount:int}
	 */
	private function liveStats(int $pollId, array $pollRow): array {
		$deadline = $this->expireToDateTime(expire: (int)($pollRow['expire'] ?? 0));

		return [
			'deadline' => $deadline,
			'closed' => ($deadline !== null && $deadline->getTimestamp() <= time()),
			'voterCount' => $this->fetchVoterCount(pollId: $pollId),
			'optionCount' => $this->fetchOptionCount(pollId: $pollId),
		];
	}//end liveStats()

	/**
	 * Assemble an unsaved PollLink entity; the caller sets the object.
	 *
	 * @param PollIdentity        $poll  Polls poll id, title and type.
	 * @param array<string,mixed> $stats `{ deadline, closed, voterCount, optionCount }`.
	 *
	 * @return PollLink The unsaved entity.
	 */
	private function newPollLink(PollIdentity $poll, array $stats): PollLink {
		$link = new PollLink();
		$link->setPollId($poll->id);
		$link->setPollTitle($poll->title);
		$link->setPollType($poll->type);
		$link->setDeadline($stats['deadline']);
		$link->setVoterCount($stats['voterCount']);
		$link->setOptionCount($stats['optionCount']);
		$link->setClosed($stats['closed']);

		return $link;
	}//end newPollLink()

	/**
	 * Unlink a poll from an object.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param int $pollId Polls poll id.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkPoll(string $objectUuid, int $pollId): void {
		$deleted = $this->pollLinkMapper->deleteByObjectAndPoll($objectUuid, $pollId);
		if ($deleted === 0) {
			throw new Exception('Poll link not found', 404);
		}
	}//end unlinkPoll()

	/**
	 * Return the linked polls for an object, with the tally counts and
	 * `closed` flag refreshed from `oc_polls_polls` on every call.
	 *
	 * Session-workaround pattern (Phase B-3 commit 73b49f3ad): Polls'
	 * own PollService can't run inside an OR controller because Polls'
	 * UserSession isn't populated, so we read directly from
	 * `oc_polls_polls` / `oc_polls_votes`. When Polls is unavailable
	 * the cached link-row values are returned unchanged.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedPolls(string $objectUuid): array {
		$links     = $this->pollLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isPollsAvailable();

		$results = [];
		foreach ($links as $link) {
			$row = $link->jsonSerialize();
			if ($available === true) {
				$row = $this->withLiveStats(row: $row, pollId: (int)$link->getPollId());
			}

			// NC Polls routes votes at /apps/polls/vote/{id}; the legacy
			// /index.php prefix breaks under the SPA's <base href> (the path
			// doubled to /apps/polls/index.php/apps/polls/vote/… on navigation).
			$row['url'] = '/apps/polls/vote/' . ($row['pollId'] ?? '');
			$results[] = $row;
		}

		return $results;
	}//end getLinkedPolls()

	/**
	 * A serialised link row with title, type, deadline, tallies and closed flag
	 * refreshed from `oc_polls_polls`; unchanged when the poll is gone.
	 *
	 * @param array<string,mixed> $row    The serialised link row.
	 * @param int                 $pollId Poll id.
	 *
	 * @return array<string,mixed>
	 */
	private function withLiveStats(array $row, int $pollId): array {
		$pollRow = $this->fetchPollRow(pollId: $pollId);
		if ($pollRow === null) {
			return $row;
		}

		$stats              = $this->liveStats(pollId: $pollId, pollRow: $pollRow);
		$row['pollTitle']   = (string)($pollRow['title'] ?? $row['pollTitle']);
		$row['pollType']    = (string)($pollRow['type'] ?? $row['pollType']);
		$row['deadline']    = $stats['deadline']?->format(DateTime::ATOM);
		$row['voterCount']  = $stats['voterCount'];
		$row['optionCount'] = $stats['optionCount'];
		$row['closed']      = $stats['closed'];

		return $row;
	}//end withLiveStats()

	/**
	 * Return polls visible to the current user (picker source).
	 *
	 * Filters out polls already deleted in Polls. Optional substring
	 * search against the poll title.
	 *
	 * @param string|null $search Optional title-substring filter.
	 *
	 * @return array<int,array{id:int,title:string,type:string,deadline:?string,closed:bool,voterCount:int,optionCount:int}>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailablePolls(?string $search = null): array {
		if ($this->isPollsAvailable() === false) {
			return [];
		}

		$user = $this->runtime->userSession()->getUser();
		if ($user === null) {
			return [];
		}

		$out = [];
		foreach ($this->ownedPollRows(uid: $user->getUID(), search: $search) as $row) {
			$out[] = $this->pickerRow(row: $row);
		}

		return $out;
	}//end getAvailablePolls()

	/**
	 * The user's non-deleted polls, newest first, or none when the query fails.
	 *
	 * @param string      $uid    Owner user id.
	 * @param string|null $search Optional title-substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function ownedPollRows(string $uid, ?string $search): array {
		try {
			return $this->ownedPollsQuery(uid: $uid, search: $search)->executeQuery()->fetchAll();
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('PollLinkService::getAvailablePolls failed: ' . $e->getMessage());
			return [];
		}
	}//end ownedPollRows()

	/**
	 * The query for the user's non-deleted polls, newest first.
	 *
	 * @param string      $uid    Owner user id.
	 * @param string|null $search Optional title-substring filter.
	 *
	 * @return IQueryBuilder
	 */
	private function ownedPollsQuery(string $uid, ?string $search): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'title', 'type', 'expire')
			->from('polls_polls')
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC');

		if ($search !== null && $search !== '') {
			$qb->andWhere($qb->expr()->iLike('title', $qb->createNamedParameter('%' . $search . '%')));
		}

		return $qb;
	}//end ownedPollsQuery()

	/**
	 * A picker row for one `polls_polls` row, with live tallies.
	 *
	 * @param array<string,mixed> $row The poll row.
	 *
	 * @return array{id:int,title:string,type:string,deadline:?string,closed:bool,voterCount:int,optionCount:int}
	 */
	private function pickerRow(array $row): array {
		$pollId = (int)($row['id'] ?? 0);
		$stats  = $this->liveStats(pollId: $pollId, pollRow: $row);

		return [
			'id' => $pollId,
			'title' => (string)($row['title'] ?? ''),
			'type' => (string)($row['type'] ?? ''),
			'deadline' => $stats['deadline']?->format(DateTime::ATOM),
			'closed' => $stats['closed'],
			'voterCount' => $stats['voterCount'],
			'optionCount' => $stats['optionCount'],
		];
	}//end pickerRow()

	/**
	 * Create a new Polls poll and link it to an OR object in one operation.
	 *
	 * Direct INSERT into `oc_polls_polls` + `oc_polls_options` (session
	 * workaround, see class docblock). The new poll is owned by the
	 * current user and uses the default access setting.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param PollDraft  $draft  Title (required), description, type, options and deadline.
	 *
	 * @return PollLink The persisted link row.
	 *
	 * @throws Exception On missing user, Polls unavailable, or create failure.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkPoll(LinkTarget $target, PollDraft $draft): PollLink {
		$user = $this->runtime->userSession()->getUser() ?? throw new Exception('No user logged in');

		if ($this->isPollsAvailable() === false) {
			throw new Exception('Polls is not available', 503);
		}

		if (trim($draft->title) === '') {
			throw new Exception('Title is required', 400);
		}

		$draft = $draft->ownedBy(owner: $user->getUID(), created: time());

		$pollId = $this->insertPollRecord(draft: $draft);

		$link = $this->buildPollLink(draft: $draft, pollId: $pollId);
		$target->stamp(link: $link, linkedBy: $draft->owner());

		return $this->pollLinkMapper->insert($link);
	}//end createAndLinkPoll()

	/**
	 * Insert the polls_polls row and its options, returning the new poll id.
	 *
	 * @param PollDraft $draft The poll, with owner and creation time.
	 *
	 * @return int The new poll id.
	 *
	 * @throws Exception When the insert fails or the new id cannot be retrieved.
	 */
	private function insertPollRecord(PollDraft $draft): int {
		try {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('polls_polls')->values($this->pollRecordValues(insert: $insert, draft: $draft));
			$insert->executeStatement();

			$pollId = $this->createdPollId();
			$this->insertPollOptions(pollId: $pollId, draft: $draft);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('Failed to create poll: ' . $e->getMessage());
			throw new Exception('Failed to create poll: ' . $e->getMessage(), 500);
		}

		return $pollId;
	}//end insertPollRecord()

	/**
	 * The id of the poll just inserted.
	 *
	 * @return int
	 *
	 * @throws Exception When the id cannot be retrieved (500).
	 */
	private function createdPollId(): int {
		// OCP\IDBConnection::lastInsertId(string $table) requires the table; a zero
		// id falls through to the explicit throw below.
		$pollId = (int)$this->db->lastInsertId('*PREFIX*polls_polls_id_seq');
		if ($pollId === 0) {
			throw new Exception('Failed to retrieve created poll id', 500);
		}

		return $pollId;
	}//end createdPollId()

	/**
	 * The `polls_polls` column values for a new poll: its content, then the fixed defaults.
	 *
	 * @param IQueryBuilder $insert The insert being built.
	 * @param PollDraft     $draft  The poll, with owner and creation time.
	 *
	 * @return array<string,mixed>
	 */
	private function pollRecordValues(IQueryBuilder $insert, PollDraft $draft): array {
		$values = [
			'type' => $insert->createNamedParameter($draft->pollType()),
			'title' => $insert->createNamedParameter($draft->storedTitle()),
			'description' => $insert->createNamedParameter($draft->description),
			'owner' => $insert->createNamedParameter($draft->owner()),
			'created' => $insert->createNamedParameter($draft->created(), IQueryBuilder::PARAM_INT),
			'expire' => $insert->createNamedParameter($draft->expire(), IQueryBuilder::PARAM_INT),
		];

		return array_merge($values, $this->namedValues(qb: $insert, columns: self::POLL_DEFAULTS));
	}//end pollRecordValues()

	/**
	 * Named parameters for fixed column values.
	 *
	 * @param IQueryBuilder                                                                     $qb      The query being built.
	 * @param array<string,array{0:int|string,1:IQueryBuilder::PARAM_INT|IQueryBuilder::PARAM_STR}> $columns Column => [value, type].
	 *
	 * @return array<string,mixed>
	 */
	private function namedValues(IQueryBuilder $qb, array $columns): array {
		$values = [];
		foreach ($columns as $column => $spec) {
			$values[$column] = $qb->createNamedParameter($spec[0], $spec[1]);
		}

		return $values;
	}//end namedValues()

	/**
	 * Assemble an unsaved PollLink entity for a created poll; the caller sets the object.
	 *
	 * @param PollDraft $draft  The poll, with owner.
	 * @param int       $pollId Newly created Polls poll id.
	 *
	 * @return PollLink The unsaved entity.
	 */
	private function buildPollLink(PollDraft $draft, int $pollId): PollLink {
		$stats = ['deadline' => $draft->deadlineDateTime(), 'closed' => false, 'voterCount' => 0, 'optionCount' => count($draft->options())];

		return $this->newPollLink(poll: new PollIdentity(id: $pollId, title: $draft->storedTitle(), type: $draft->pollType()), stats: $stats);
	}//end buildPollLink()

	/**
	 * Insert poll options.
	 *
	 * @param int       $pollId Created poll id.
	 * @param PollDraft $draft  The poll, with owner and creation time.
	 *
	 * @return void
	 */
	private function insertPollOptions(int $pollId, PollDraft $draft): void {
		$order = 0;
		foreach ($draft->options() as $option) {
			$text = trim((string)$option);
			if ($text === '') {
				continue;
			}

			$order++;
			$timestamp = $this->optionTimestamp(text: $text, draft: $draft);
			$this->insertPollOption(option: new PollOptionRow(pollId: $pollId, text: $text, order: $order, timestamp: $timestamp), draft: $draft);
		}
	}//end insertPollOptions()

	/**
	 * Insert one poll option.
	 *
	 * @param PollOptionRow $option The option of the created poll.
	 * @param PollDraft     $draft  The poll, with owner and creation time.
	 *
	 * @return void
	 */
	private function insertPollOption(PollOptionRow $option, PollDraft $draft): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('polls_options')->values(
			array_merge(
				$this->optionContentValues(qb: $qb, option: $option),
				$this->optionMetaValues(qb: $qb, draft: $draft)
			)
		);
		$qb->executeStatement();
	}//end insertPollOption()

	/**
	 * The option's timestamp: the parsed date for a date poll, else 0.
	 *
	 * @param string    $text  The option text.
	 * @param PollDraft $draft The poll.
	 *
	 * @return int
	 */
	private function optionTimestamp(string $text, PollDraft $draft): int {
		if ($draft->pollType() !== 'datePoll') {
			return 0;
		}

		$parsedTime = strtotime($text);
		if ($parsedTime === false) {
			return 0;
		}

		return $parsedTime;
	}//end optionTimestamp()

	/**
	 * The `polls_options` columns that describe the option itself.
	 *
	 * @param IQueryBuilder $qb     The insert being built.
	 * @param PollOptionRow $option The option of the created poll.
	 *
	 * @return array<string,mixed>
	 */
	private function optionContentValues(IQueryBuilder $qb, PollOptionRow $option): array {
		return [
			'poll_id' => $qb->createNamedParameter($option->pollId, IQueryBuilder::PARAM_INT),
			'poll_option_text' => $qb->createNamedParameter(mb_substr($option->text, 0, 1024)),
			'poll_option_hash' => $qb->createNamedParameter($option->hash()),
			'order' => $qb->createNamedParameter($option->order, IQueryBuilder::PARAM_INT),
			'timestamp' => $qb->createNamedParameter($option->timestamp, IQueryBuilder::PARAM_INT),
		];
	}//end optionContentValues()

	/**
	 * The `polls_options` bookkeeping columns: fixed flags, owner and creation time.
	 *
	 * @param IQueryBuilder $qb    The insert being built.
	 * @param PollDraft     $draft The poll, with owner and creation time.
	 *
	 * @return array<string,mixed>
	 */
	private function optionMetaValues(IQueryBuilder $qb, PollDraft $draft): array {
		$int = IQueryBuilder::PARAM_INT;

		return [
			'duration' => $qb->createNamedParameter(0, $int),
			'confirmed' => $qb->createNamedParameter(0, $int),
			'owner' => $qb->createNamedParameter($draft->owner()),
			'released' => $qb->createNamedParameter(0, $int),
			'deleted' => $qb->createNamedParameter(0, $int),
			'created' => $qb->createNamedParameter($draft->created(), $int),
		];
	}//end optionMetaValues()

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
			$qb->select('id', 'title', 'type', 'expire', 'owner', 'deleted')->from('polls_polls');
			$this->whereLivePoll(qb: $qb, pollId: $pollId);
			$row = $qb->executeQuery()->fetch();
			if ($row === false) {
				return null;
			}

			return $row;
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('fetchPollRow failed: ' . $e->getMessage());
			return null;
		}
	}//end fetchPollRow()

	/**
	 * Restrict a `polls_polls` query to one poll, when it is not deleted.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param int           $pollId Poll id.
	 *
	 * @return void
	 */
	private function whereLivePoll(IQueryBuilder $qb, int $pollId): void {
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
		$this->andNotDeleted(qb: $qb);
	}//end whereLivePoll()

	/**
	 * Restrict a query on a poll's child table (`poll_id`) to the non-deleted rows of one poll.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param int           $pollId Poll id.
	 *
	 * @return void
	 */
	private function whereLiveRowsOf(IQueryBuilder $qb, int $pollId): void {
		$qb->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)));
		$this->andNotDeleted(qb: $qb);
	}//end whereLiveRowsOf()

	/**
	 * Keep only rows that are not deleted.
	 *
	 * @param IQueryBuilder $qb The query being built.
	 *
	 * @return void
	 */
	private function andNotDeleted(IQueryBuilder $qb): void {
		$qb->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
	}//end andNotDeleted()

	/**
	 * Distinct user count that has cast at least one non-deleted vote.
	 *
	 * @param int $pollId Poll id.
	 *
	 * @return int
	 */
	private function fetchVoterCount(int $pollId): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('user_id')->from('polls_votes');
			$this->whereLiveRowsOf(qb: $qb, pollId: $pollId);
			return count($qb->executeQuery()->fetchAll());
		} catch (Throwable $e) {
			return 0;
		}
	}//end fetchVoterCount()

	/**
	 * Count of non-deleted options on a poll.
	 *
	 * @param int $pollId Poll id.
	 *
	 * @return int
	 */
	private function fetchOptionCount(int $pollId): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'cnt'))->from('polls_options');
			$this->whereLiveRowsOf(qb: $qb, pollId: $pollId);
			// A missing count (false or null) casts to 0.
			return (int)$qb->executeQuery()->fetchOne();
		} catch (Throwable $e) {
			return 0;
		}
	}//end fetchOptionCount()

	/**
	 * Convert a Polls `expire` epoch column to a DateTime.
	 *
	 * Polls treats 0 as "no deadline".
	 *
	 * @param int $expire Epoch seconds or zero.
	 *
	 * @return DateTime|null
	 */
	private function expireToDateTime(int $expire): ?DateTime {
		if ($expire <= 0) {
			return null;
		}

		try {
			return new DateTime('@' . $expire);
		} catch (Throwable $e) {
			return null;
		}
	}//end expireToDateTime()
}//end class
