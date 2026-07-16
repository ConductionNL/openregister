<?php

/**
 * PollLinkService — Tier-2 polls integration service.
 *
 * Composes the {@see PollLinkMapper} with direct queries into NC Polls'
 * own tables (`oc_polls_polls`, `oc_polls_options`, `oc_polls_votes`)
 * to provide the picker + inline-create UX surface area:
 *
 *   - linkPoll(uuid, registerId, schemaId, pollId)          — link existing poll
 *   - createAndLinkPoll(uuid, ..., title, description,
 *                       type, options, deadline)            — create + link new
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
use DateTimeInterface;
use Exception;
use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapper;
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
class PollLinkService
{
    /**
     * Constructor.
     *
     * @param PollLinkMapper  $pollLinkMapper Persistence for link rows.
     * @param IDBConnection   $db             Database connection.
     * @param IAppManager     $appManager     NC app manager.
     * @param IUserSession    $userSession    Active session.
     * @param LoggerInterface $logger         Logger.
     */
    public function __construct(
        private readonly PollLinkMapper $pollLinkMapper,
        private readonly IDBConnection $db,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC Polls is installed + enabled for the current user.
     *
     * @return bool
     */
    public function isPollsAvailable(): bool
    {
        return $this->appManager->isEnabledForUser('polls');
    }//end isPollsAvailable()

    /**
     * Link an existing Polls poll to an OR object.
     *
     * Idempotent: a duplicate link raises a 409 Exception (callers
     * should surface as HTTP 409 to the UI).
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param int    $pollId     Polls poll id.
     *
     * @return PollLink The persisted link row.
     *
     * @throws Exception On missing user, missing poll (404), duplicate (409).
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function linkPoll(string $objectUuid, int $registerId, int $schemaId, int $pollId): PollLink
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $existing = $this->pollLinkMapper->findByObjectAndPoll($objectUuid, $pollId);
        if ($existing !== null) {
            throw new Exception('Poll already linked to this object', 409);
        }

        if ($this->isPollsAvailable() === false) {
            throw new Exception('Polls is not available', 503);
        }

        $pollRow = $this->fetchPollRow(pollId: $pollId);
        if ($pollRow === null) {
            throw new Exception('Poll not found', 404);
        }

        $deadline    = $this->expireToDateTime(expire: (int) ($pollRow['expire'] ?? 0));
        $closed      = ($deadline !== null && $deadline->getTimestamp() <= time());
        $voterCount  = $this->fetchVoterCount(pollId: $pollId);
        $optionCount = $this->fetchOptionCount(pollId: $pollId);

        $link = new PollLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setPollId($pollId);
        $link->setPollTitle((string) ($pollRow['title'] ?? ''));
        $link->setPollType((string) ($pollRow['type'] ?? ''));
        $link->setDeadline($deadline);
        $link->setVoterCount($voterCount);
        $link->setOptionCount($optionCount);
        $link->setClosed($closed);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());

        return $this->pollLinkMapper->insert($link);
    }//end linkPoll()

    /**
     * Unlink a poll from an object.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $pollId     Polls poll id.
     *
     * @return void
     *
     * @throws Exception When no matching link is found (404).
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function unlinkPoll(string $objectUuid, int $pollId): void
    {
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Conditional enrichment path (Polls
     *   available/unavailable) combined with per-link row hydration requires nested guards;
     *   the logic cannot be split without losing the "degrade gracefully" contract.
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function getLinkedPolls(string $objectUuid): array
    {
        $links     = $this->pollLinkMapper->findByObjectUuid($objectUuid);
        $available = $this->isPollsAvailable();

        $results = [];
        foreach ($links as $link) {
            $row = $link->jsonSerialize();

            if ($available === true) {
                $pollId  = (int) $link->getPollId();
                $pollRow = $this->fetchPollRow(pollId: $pollId);

                if ($pollRow !== null) {
                    $deadline           = $this->expireToDateTime(expire: (int) ($pollRow['expire'] ?? 0));
                    $row['pollTitle']   = (string) ($pollRow['title'] ?? $row['pollTitle']);
                    $row['pollType']    = (string) ($pollRow['type'] ?? $row['pollType']);
                    $row['deadline']    = $deadline?->format(DateTime::ATOM);
                    $row['voterCount']  = $this->fetchVoterCount(pollId: $pollId);
                    $row['optionCount'] = $this->fetchOptionCount(pollId: $pollId);
                    $row['closed']      = ($deadline !== null && $deadline->getTimestamp() <= time());
                }
            }

            // NC Polls routes votes at /apps/polls/vote/{id}; the legacy
            // /index.php prefix breaks under the SPA's <base href> (the path
            // doubled to /apps/polls/index.php/apps/polls/vote/… on navigation).
            $row['url'] = '/apps/polls/vote/'.($row['pollId'] ?? '');
            $results[]  = $row;
        }//end foreach

        return $results;
    }//end getLinkedPolls()

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
    public function getAvailablePolls(?string $search=null): array
    {
        if ($this->isPollsAvailable() === false) {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return [];
        }

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'title', 'type', 'expire')
                ->from('polls_polls')
                ->where($qb->expr()->eq('owner', $qb->createNamedParameter($user->getUID())))
                ->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
                ->orderBy('id', 'DESC');

            if ($search !== null && $search !== '') {
                $qb->andWhere(
                    $qb->expr()->iLike('title', $qb->createNamedParameter('%'.$search.'%'))
                );
            }

            $rows = $qb->executeQuery()->fetchAll();
        } catch (Throwable $e) {
            $this->logger->warning('PollLinkService::getAvailablePolls failed: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $pollId   = (int) ($row['id'] ?? 0);
            $deadline = $this->expireToDateTime(expire: (int) ($row['expire'] ?? 0));
            $out[]    = [
                'id'          => $pollId,
                'title'       => (string) ($row['title'] ?? ''),
                'type'        => (string) ($row['type'] ?? ''),
                'deadline'    => $deadline?->format(DateTime::ATOM),
                'closed'      => ($deadline !== null && $deadline->getTimestamp() <= time()),
                'voterCount'  => $this->fetchVoterCount(pollId: $pollId),
                'optionCount' => $this->fetchOptionCount(pollId: $pollId),
            ];
        }

        return $out;
    }//end getAvailablePolls()

    /**
     * Create a new Polls poll and link it to an OR object in one operation.
     *
     * Direct INSERT into `oc_polls_polls` + `oc_polls_options` (session
     * workaround, see class docblock). The new poll is owned by the
     * current user and uses the default access setting.
     *
     * @param string                 $objectUuid  Parent OR object uuid.
     * @param int                    $registerId  OR register id.
     * @param int                    $schemaId    OR schema id.
     * @param string                 $title       Poll title (required).
     * @param string                 $description Poll description (optional).
     * @param string                 $type        Poll type: `textPoll` / `datePoll`.
     * @param array<int,string>      $options     Option labels.
     * @param DateTimeInterface|null $deadline    Optional deadline.
     *
     * @return PollLink The persisted link row.
     *
     * @throws Exception On missing user, Polls unavailable, or create failure.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)   PHPMD 2.x accumulates the complexity
     *   of insertPollRecord and buildPollLink (extracted helpers) into this orchestrator;
     *   the method itself only validates preconditions and delegates to those helpers.
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Poll creation requires seven distinct
     *   parameters (objectUuid, registerId, schemaId, title, description, type, options,
     *   deadline); grouping them into a DTO would add an extra abstraction layer with no
     *   functional benefit since callers already have all values as discrete variables.
     *
     * @spec openspec/specs/generic-integrations/spec.md
     */
    public function createAndLinkPoll(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $title,
        string $description,
        string $type,
        array $options,
        ?DateTimeInterface $deadline
    ): PollLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        if ($this->isPollsAvailable() === false) {
            throw new Exception('Polls is not available', 503);
        }

        if (trim($title) === '') {
            throw new Exception('Title is required', 400);
        }

        $normalisedType = $this->normalisePollType(type: $type);
        $uid            = $user->getUID();

        $pollId = $this->insertPollRecord(
            uid: $uid,
            title: $title,
            description: $description,
            type: $normalisedType,
            options: $options,
            deadline: $deadline
        );

        $link = $this->buildPollLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            pollId: $pollId,
            title: $title,
            normalisedType: $normalisedType,
            options: $options,
            deadline: $deadline,
            uid: $uid
        );

        return $this->pollLinkMapper->insert($link);
    }//end createAndLinkPoll()

    /**
     * Insert the polls_polls row and its options, returning the new poll id.
     *
     * @param string                 $uid         Owner user id.
     * @param string                 $title       Poll title.
     * @param string                 $description Poll description.
     * @param string                 $type        Normalised poll type.
     * @param array<int,string>      $options     Option labels.
     * @param DateTimeInterface|null $deadline    Optional deadline.
     *
     * @return int The new poll id.
     *
     * @throws Exception When the insert fails or the new id cannot be retrieved.
     */
    private function insertPollRecord(
        string $uid,
        string $title,
        string $description,
        string $type,
        array $options,
        ?DateTimeInterface $deadline
    ): int {
        $now    = time();
        $expire = 0;
        if ($deadline !== null) {
            $expire = $deadline->getTimestamp();
        }

        try {
            $insert = $this->db->getQueryBuilder();
            $insert->insert('polls_polls')
                ->values(
                    [
                        'type'            => $insert->createNamedParameter($type),
                        'title'           => $insert->createNamedParameter(mb_substr($title, 0, 128)),
                        'description'     => $insert->createNamedParameter($description),
                        'owner'           => $insert->createNamedParameter($uid),
                        'created'         => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                        'expire'          => $insert->createNamedParameter($expire, IQueryBuilder::PARAM_INT),
                        'deleted'         => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'access'          => $insert->createNamedParameter('private'),
                        'anonymous'       => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'allow_maybe'     => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                        'allow_proposals' => $insert->createNamedParameter('disallow'),
                        'show_results'    => $insert->createNamedParameter('always'),
                        'admin_access'    => $insert->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'allow_comment'   => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                        'hide_booked_up'  => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                        'use_no'          => $insert->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                        'voting_variant'  => $insert->createNamedParameter('simple'),
                    ]
                );
            $insert->executeStatement();

            $pollId = (int) $this->db->lastInsertId('oc_polls_polls_id_seq');
            if ($pollId === 0) {
                // Fallback for drivers without sequence support.
                $pollId = (int) $this->db->lastInsertId();
            }

            if ($pollId === 0) {
                throw new Exception('Failed to retrieve created poll id', 500);
            }

            $this->insertPollOptions(pollId: $pollId, owner: $uid, type: $type, options: $options, now: $now);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to create poll: '.$e->getMessage());
            throw new Exception('Failed to create poll: '.$e->getMessage(), 500);
        }//end try

        return $pollId;
    }//end insertPollRecord()

    /**
     * Assemble an unsaved PollLink entity from poll creation data.
     *
     * @param string                 $objectUuid     Parent OR object uuid.
     * @param int                    $registerId     OR register id.
     * @param int                    $schemaId       OR schema id.
     * @param int                    $pollId         Newly created Polls poll id.
     * @param string                 $title          Poll title.
     * @param string                 $normalisedType Normalised poll type.
     * @param array<int,string>      $options        Option labels.
     * @param DateTimeInterface|null $deadline       Optional deadline.
     * @param string                 $uid            Owner user id.
     *
     * @return PollLink The unsaved entity.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required to assemble all
     *   PollLink fields from the caller's arguments without a mutable context object.
     */
    private function buildPollLink(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        int $pollId,
        string $title,
        string $normalisedType,
        array $options,
        ?DateTimeInterface $deadline,
        string $uid
    ): PollLink {
        $deadlineDateTime = null;
        if ($deadline !== null) {
            try {
                $deadlineDateTime = new DateTime('@'.$deadline->getTimestamp());
            } catch (Throwable $e) {
                $deadlineDateTime = null;
            }
        }

        $link = new PollLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setPollId($pollId);
        $link->setPollTitle(mb_substr($title, 0, 128));
        $link->setPollType($normalisedType);
        $link->setDeadline($deadlineDateTime);
        $link->setVoterCount(0);
        $link->setOptionCount(count($options));
        $link->setClosed(false);
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $link;
    }//end buildPollLink()

    /**
     * Insert poll options.
     *
     * @param int               $pollId  Created poll id.
     * @param string            $owner   Owner uid.
     * @param string            $type    Normalised poll type.
     * @param array<int,string> $options Option labels.
     * @param int               $now     Created timestamp.
     *
     * @return void
     */
    private function insertPollOptions(int $pollId, string $owner, string $type, array $options, int $now): void
    {
        $order = 0;
        foreach ($options as $option) {
            $text = trim((string) $option);
            if ($text === '') {
                continue;
            }

            $order++;

            $hash      = substr(hash('sha256', $pollId.'-'.$text.'-'.$order), 0, 32);
            $timestamp = 0;
            if ($type === 'datePoll') {
                $parsedTime = strtotime($text);
                if ($parsedTime !== false) {
                    $timestamp = $parsedTime;
                }
            }

            $qb = $this->db->getQueryBuilder();
            $qb->insert('polls_options')->values(
                    [
                        'poll_id'          => $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT),
                        'poll_option_text' => $qb->createNamedParameter(mb_substr($text, 0, 1024)),
                        'poll_option_hash' => $qb->createNamedParameter($hash),
                        'order'            => $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT),
                        'timestamp'        => $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT),
                        'duration'         => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'confirmed'        => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'owner'            => $qb->createNamedParameter($owner),
                        'released'         => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'deleted'          => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                        'created'          => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                    ]
                    );
            $qb->executeStatement();
        }//end foreach
    }//end insertPollOptions()

    /**
     * Fetch a poll row from `oc_polls_polls`.
     *
     * @param int $pollId Poll id.
     *
     * @return array<string,mixed>|null
     */
    private function fetchPollRow(int $pollId): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'title', 'type', 'expire', 'owner', 'deleted')
                ->from('polls_polls')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

            $row = $qb->executeQuery()->fetch();
            if ($row === false) {
                return null;
            }

            return $row;
        } catch (Throwable $e) {
            $this->logger->debug('fetchPollRow failed: '.$e->getMessage());
            return null;
        }
    }//end fetchPollRow()

    /**
     * Distinct user count that has cast at least one non-deleted vote.
     *
     * @param int $pollId Poll id.
     *
     * @return int
     */
    private function fetchVoterCount(int $pollId): int
    {
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
     * Count of non-deleted options on a poll.
     *
     * @param int $pollId Poll id.
     *
     * @return int
     */
    private function fetchOptionCount(int $pollId): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('*', 'cnt'))
                ->from('polls_options')
                ->where($qb->expr()->eq('poll_id', $qb->createNamedParameter($pollId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('deleted', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
            $fetchedCount = $qb->executeQuery()->fetchOne();
            if ($fetchedCount === false || $fetchedCount === null) {
                return 0;
            }

            return (int) $fetchedCount;
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
    private function expireToDateTime(int $expire): ?DateTime
    {
        if ($expire <= 0) {
            return null;
        }

        try {
            return new DateTime('@'.$expire);
        } catch (Throwable $e) {
            return null;
        }
    }//end expireToDateTime()

    /**
     * Normalise short-form poll types to Polls' canonical strings.
     *
     * Accepted aliases:
     *  - `text` / `textPoll` → `textPoll`
     *  - `date` / `datePoll` → `datePoll`
     *
     * Anything else falls through unchanged so future Polls types
     * don't need a code change here.
     *
     * @param string $type Caller-supplied type.
     *
     * @return string
     */
    private function normalisePollType(string $type): string
    {
        $lower = strtolower(trim($type));
        if ($lower === 'text' || $lower === 'textpoll') {
            return 'textPoll';
        }

        if ($lower === 'date' || $lower === 'datepoll') {
            return 'datePoll';
        }

        if ($type === '') {
            return 'textPoll';
        }

        return $type;
    }//end normalisePollType()
}//end class
