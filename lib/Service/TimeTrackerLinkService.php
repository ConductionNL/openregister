<?php

/**
 * TimeTrackerLinkService — Tier-2 time-tracker (NC TimeManager)
 * integration service.
 *
 * Composes the {@see TimeTrackerLinkMapper} with NC TimeManager's
 * `OCA\TimeManager\Db\ClientMapper` + `TaskMapper` to expose the Tier-2
 * surface:
 *
 *   - linkEntry(uuid, registerId, schemaId, entryType, id)
 *       — link an existing client / task / time entry
 *   - createAndLinkClient(uuid, registerId, schemaId, name)
 *       — create a new TimeManager client and link it
 *   - unlink(uuid, entryId)
 *       — remove a link (the upstream entry stays in TimeManager)
 *   - getLinkedEntries(uuid)
 *       — list linked entries, refreshing cached
 *       name/duration/billable/started_at when the row is older than 24h
 *   - getAvailableClients(?search)
 *       — picker source listing the current user's TimeManager clients
 *
 * NC TimeManager exposes its persistence via `ClientMapper` and
 * `TaskMapper` (extending the shared `ObjectMapper`). Both are resolved
 * lazily through the server container behind a `class_exists` +
 * `Throwable` guard so this service loads even when TimeManager is not
 * installed (ADR-019 AD-23 graceful degradation): when TimeManager is
 * missing or a call throws, the stored link row is returned as-is so
 * historical references survive.
 *
 * TimeManager entries are user-scoped (rows carry a `user_id`), so every
 * mutating + picker call is scoped to the active session's UID.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\TimeTrackerLink;
use OCA\OpenRegister\Db\TimeTrackerLinkMapper;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TimeTrackerLinkService.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Composes mapper +
 *     NC TimeManager ClientMapper + TaskMapper (late-bound) + db + user
 *     session + app manager + container + logger. Each dependency is
 *     required for one of the Tier-2 flows (link, create, unlink, list,
 *     picker, cache refresh, graceful degradation).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Tier-2 service implements
 * linkEntry/createAndLinkClient/unlink/getLinkedEntries/getAvailableClients plus
 * resolveMapper/requireUid/normaliseEntryType/idComponents/fetchEntryInfo/hydrateLink/insertClient/normaliseRow/isStale/refreshEntry;
 * each is required for a distinct flow in the integration surface.
 */
class TimeTrackerLinkService
{
    private const REQUIRED_APP = 'timemanager';

    private const CLIENT_MAPPER = 'OCA\\TimeManager\\Db\\ClientMapper';

    private const TASK_MAPPER = 'OCA\\TimeManager\\Db\\TaskMapper';

    private const ENTRY_CLIENT = 'client';

    private const ENTRY_TASK = 'task';

    private const ENTRY_TIME = 'time';

    private const STALE_AFTER = 86400;
    // 24 hours in seconds.

    /**
     * Constructor.
     *
     * @param TimeTrackerLinkMapper $timeTrackerLinkMapper Persistence for link rows.
     * @param IDBConnection         $db                    DB connection for upstream client/task lookups.
     * @param ContainerInterface    $container             Container for late-bound TimeManager classes.
     * @param IAppManager           $appManager            NC app manager.
     * @param IUserSession          $userSession           Active session.
     * @param LoggerInterface       $logger                Logger.
     *
     * @SuppressWarnings(PHPMD.LongVariable) $timeTrackerLinkMapper follows the repo naming convention for OR
     * link-table mappers; $timeTrackerLinkMapper is the exact constructor-injected variable name used across
     * the Tier-2 service pattern and abbreviating it would obscure its purpose.
     */
    public function __construct(
        private readonly TimeTrackerLinkMapper $timeTrackerLinkMapper,
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether NC TimeManager is installed + enabled for the current user.
     *
     * @return bool
     */
    public function isTimeManagerAvailable(): bool
    {
        return $this->appManager->isEnabledForUser(self::REQUIRED_APP);
    }//end isTimeManagerAvailable()

    /**
     * Resolve a late-bound NC TimeManager mapper lazily.
     *
     * Returns null when TimeManager is absent or the class can't be
     * resolved, so callers degrade gracefully (ADR-019 AD-23).
     *
     * @param string $class The fully-qualified TimeManager mapper class.
     *
     * @return object|null The mapper instance or null.
     */
    private function resolveMapper(string $class): ?object
    {
        if ($this->isTimeManagerAvailable() === false || class_exists($class) === false) {
            return null;
        }

        try {
            return $this->container->get($class);
        } catch (Throwable $e) {
            $this->logger->debug('TimeTrackerLinkService: '.$class.' unavailable: '.$e->getMessage());
            return null;
        }
    }//end resolveMapper()

    /**
     * Active session UID, or throw if no user is logged in.
     *
     * @return string The user id.
     *
     * @throws Exception When there is no active user.
     */
    private function requireUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in', 401);
        }

        return $user->getUID();
    }//end requireUid()

    /**
     * Normalise + validate an entry-type discriminator.
     *
     * @param string $entryType The raw entry type.
     *
     * @return string The normalised entry type.
     *
     * @throws Exception On an unknown entry type (400).
     */
    private function normaliseEntryType(string $entryType): string
    {
        $entryType = strtolower(trim($entryType));
        if (in_array($entryType, [self::ENTRY_CLIENT, self::ENTRY_TASK, self::ENTRY_TIME], true) === false) {
            throw new Exception('Unknown entry type', 400);
        }

        return $entryType;
    }//end normaliseEntryType()

    /**
     * Link an existing NC TimeManager entry to an OR object.
     *
     * Idempotent: a duplicate link raises a 409 Exception. Entry metadata
     * (name/duration/billable/started_at) is cached at link time.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param string $entryType  Entry kind (`client`|`task`|`time`).
     * @param string $id         Upstream entry uuid.
     *
     * @return TimeTrackerLink The persisted link row.
     *
     * @throws Exception On missing user (401), bad type (400), missing
     *                   entry (404), duplicate (409), TimeManager
     *                   unavailable (503).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the link-entry contract is owned by the integration-time-tracker capability.
     */
    public function linkEntry(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $entryType,
        string $id
    ): TimeTrackerLink {
        $uid       = $this->requireUid();
        $entryType = $this->normaliseEntryType(entryType: $entryType);

        $id = trim($id);
        if ($id === '') {
            throw new Exception('Entry id is required', 400);
        }

        if ($this->isTimeManagerAvailable() === false) {
            throw new Exception('NC TimeManager is not available', 503);
        }

        $components = $this->idComponents(entryType: $entryType, id: $id);

        $existing = $this->timeTrackerLinkMapper->findByObjectAndEntry(
            $objectUuid,
            $entryType,
            $components['client_id'],
            $components['task_id'],
            $components['time_id']
        );
        if ($existing !== null) {
            throw new Exception('Entry already linked to this object', 409);
        }

        $info = $this->fetchEntryInfo(entryType: $entryType, id: $id, uid: $uid);
        if ($info === null) {
            throw new Exception('TimeManager entry not found', 404);
        }

        $link = $this->hydrateLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            entryType: $entryType,
            id: $id,
            info: $info,
            uid: $uid
        );

        return $this->timeTrackerLinkMapper->insert($link);
    }//end linkEntry()

    /**
     * Create a new NC TimeManager client and link it to an OR object.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param int    $registerId OR register id.
     * @param int    $schemaId   OR schema id.
     * @param string $name       New client name.
     *
     * @return TimeTrackerLink The persisted link row.
     *
     * @throws Exception On missing user (401), empty name (400),
     *                   TimeManager unavailable (503), create failure (500).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link-client contract is
     *       owned by the integration-time-tracker capability.
     */
    public function createAndLinkClient(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $name
    ): TimeTrackerLink {
        $uid = $this->requireUid();

        $name = trim($name);
        if ($name === '') {
            throw new Exception('Client name is required', 400);
        }

        $clientMapper = $this->resolveMapper(class: self::CLIENT_MAPPER);
        if ($clientMapper === null) {
            throw new Exception('NC TimeManager is not available', 503);
        }

        try {
            $clientUuid = $this->insertClient(clientMapper: $clientMapper, name: $name, uid: $uid);
        } catch (Throwable $e) {
            $this->logger->warning('TimeTrackerLinkService::createAndLinkClient failed: '.$e->getMessage());
            throw new Exception('Failed to create TimeManager client', 500);
        }

        $info = [
            'name'      => $name,
            'duration'  => null,
            'billable'  => null,
            'startedAt' => null,
        ];

        $link = $this->hydrateLink(
            objectUuid: $objectUuid,
            registerId: $registerId,
            schemaId: $schemaId,
            entryType: self::ENTRY_CLIENT,
            id: $clientUuid,
            info: $info,
            uid: $uid
        );

        return $this->timeTrackerLinkMapper->insert($link);
    }//end createAndLinkClient()

    /**
     * Insert a new TimeManager client row and return its uuid.
     *
     * TimeManager's ClientMapper extends the shared ObjectMapper whose
     * `insert()` expects a populated entity; we build the entity via the
     * mapper's entity class so this stays decoupled from the upstream
     * concrete class at compile time.
     *
     * @param object $clientMapper The late-bound TimeManager ClientMapper.
     * @param string $name         The client name.
     * @param string $uid          The owning user id.
     *
     * @return string The new client uuid.
     *
     * @throws Throwable On any upstream failure.
     */
    private function insertClient(object $clientMapper, string $name, string $uid): string
    {
        $uuid = $this->generateUuid();

        $tableName = $clientMapper->getTableName();
        $now       = (new DateTime())->format('Y-m-d\TH:i:sP');

        $qb = $this->db->getQueryBuilder();
        $qb->insert($tableName)
            ->values(
                [
                    'name'    => $qb->createNamedParameter($name),
                    'user_id' => $qb->createNamedParameter($uid),
                    'uuid'    => $qb->createNamedParameter($uuid),
                    'created' => $qb->createNamedParameter($now),
                    'changed' => $qb->createNamedParameter($now),
                    'commit'  => $qb->createNamedParameter(''),
                ]
            );
        $qb->executeStatement();

        return $uuid;
    }//end insertClient()

    /**
     * Build a TimeTrackerLink from normalised entry info.
     *
     * @param string              $objectUuid Parent OR object uuid.
     * @param int                 $registerId OR register id.
     * @param int                 $schemaId   OR schema id.
     * @param string              $entryType  Entry kind.
     * @param string              $id         Upstream entry uuid.
     * @param array<string,mixed> $info       Normalised entry info.
     * @param string              $uid        The linking user id.
     *
     * @return TimeTrackerLink
     */
    private function hydrateLink(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $entryType,
        string $id,
        array $info,
        string $uid
    ): TimeTrackerLink {
        $components = $this->idComponents(entryType: $entryType, id: $id);

        $link = new TimeTrackerLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setEntryType($entryType);
        $link->setClientId($components['client_id']);
        $link->setTaskId($components['task_id']);
        $link->setTimeId($components['time_id']);
        $link->setName((string) ($info['name'] ?? ''));
        $link->setDuration($info['duration'] ?? null);
        $link->setBillable($info['billable'] ?? null);
        $link->setStartedAt($info['startedAt'] ?? null);
        $link->setLinkedBy($uid);
        $link->setLinkedAt(new DateTime());

        return $link;
    }//end hydrateLink()

    /**
     * Map an entry id into the three id columns per entry type.
     *
     * @param string $entryType Entry kind.
     * @param string $id        Upstream entry uuid.
     *
     * @return array{client_id:?string,task_id:?string,time_id:?string}
     */
    private function idComponents(string $entryType, string $id): array
    {
        $components = [
            'client_id' => null,
            'task_id'   => null,
            'time_id'   => null,
        ];

        if ($entryType === self::ENTRY_CLIENT) {
            $components['client_id'] = $id;
            return $components;
        }

        if ($entryType === self::ENTRY_TASK) {
            $components['task_id'] = $id;
            return $components;
        }

        $components['time_id'] = $id;
        return $components;
    }//end idComponents()

    /**
     * Unlink an entry from an object.
     *
     * Does NOT delete the upstream entry — it stays in NC TimeManager.
     *
     * @param string $objectUuid Parent OR object uuid.
     * @param string $entryId    Upstream entry uuid.
     *
     * @return void
     *
     * @throws Exception On missing user (401) or no matching link (404).
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink contract is owned by the integration-time-tracker capability.
     */
    public function unlink(string $objectUuid, string $entryId): void
    {
        $this->requireUid();

        $deleted = $this->timeTrackerLinkMapper->deleteByObjectAndEntryId($objectUuid, $entryId);
        if ($deleted === 0) {
            throw new Exception('Time-tracker link not found', 404);
        }
    }//end unlink()

    /**
     * Return the linked entries for an object, refreshing the cached
     * name/duration/billable/started_at columns when a row is older
     * than 24h.
     *
     * @param string $objectUuid Parent OR object uuid.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the linked-entries listing contract is
     *       owned by the integration-time-tracker capability.
     */
    public function getLinkedEntries(string $objectUuid): array
    {
        $links     = $this->timeTrackerLinkMapper->findByObjectUuid($objectUuid);
        $available = $this->isTimeManagerAvailable();
        $uid       = $this->userSession->getUser()?->getUID();

        $results = [];
        foreach ($links as $link) {
            if ($available === true && $uid !== null && $this->isStale(link: $link) === true) {
                $link = $this->refreshLink(link: $link, uid: $uid);
            }

            $results[] = $this->rowFromLink(link: $link);
        }

        return $results;
    }//end getLinkedEntries()

    /**
     * Convert a TimeTrackerLink into the registry leaf-row shape (the
     * three-kind shape the bespoke tab + TimeProvider share).
     *
     * @param TimeTrackerLink $link Link row.
     *
     * @return array<string,mixed>
     */
    private function rowFromLink(TimeTrackerLink $link): array
    {
        $entryType = (string) $link->getEntryType();
        $id        = $this->entryId(link: $link);

        return [
            'id'        => $id,
            'kind'      => $entryType,
            'type'      => $entryType,
            'title'     => (string) $link->getName(),
            'name'      => (string) $link->getName(),
            'clientId'  => $link->getClientId(),
            'taskId'    => $link->getTaskId(),
            'timeId'    => $link->getTimeId(),
            'duration'  => $link->getDuration(),
            'billable'  => $link->getBillable(),
            'startedAt' => $link->getStartedAt()?->format(DateTime::ATOM),
            'url'       => $this->entryDeepLink(entryType: $entryType, id: $id),
            'data'      => $link->jsonSerialize(),
        ];
    }//end rowFromLink()

    /**
     * The upstream entry id carried by the link, per entry type.
     *
     * @param TimeTrackerLink $link Link row.
     *
     * @return string
     */
    private function entryId(TimeTrackerLink $link): string
    {
        switch ((string) $link->getEntryType()) {
            case self::ENTRY_TASK:
                return (string) $link->getTaskId();
            case self::ENTRY_TIME:
                return (string) $link->getTimeId();
            default:
                return (string) $link->getClientId();
        }
    }//end entryId()

    /**
     * Return the current user's NC TimeManager clients (picker source).
     *
     * Returns an empty array when TimeManager is unavailable.
     *
     * @param string|null $search Optional name-substring filter.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec exclude ADR-019 Tier-2 integration link-service facade; the picker-source contract is owned by the integration-time-tracker capability.
     */
    public function getAvailableClients(?string $search=null): array
    {
        $clientMapper = $this->resolveMapper(class: self::CLIENT_MAPPER);
        $uid          = $this->userSession->getUser()?->getUID();
        if ($clientMapper === null || $uid === null) {
            return [];
        }

        $rows = $this->fetchClientRows(clientMapper: $clientMapper, uid: $uid, search: $search);

        $out = [];
        foreach ($rows as $row) {
            $uuid = '';
            if (isset($row->uuid) === true) {
                $uuid = (string) $row->uuid;
            }

            $name = '';
            if (isset($row->name) === true) {
                $name = (string) $row->name;
            }

            if ($uuid === '') {
                continue;
            }

            $out[] = [
                'id'    => $uuid,
                'kind'  => self::ENTRY_CLIENT,
                'name'  => $name,
                'title' => $name,
            ];
        }//end foreach

        return $out;
    }//end getAvailableClients()

    /**
     * Run the LIKE/scan query for the current user's TimeManager clients.
     *
     * @param object      $clientMapper The late-bound TimeManager ClientMapper.
     * @param string      $uid          The owning user id.
     * @param string|null $search       Optional name-substring filter.
     *
     * @return array<int,object> Matching rows (loose-typed).
     */
    private function fetchClientRows(object $clientMapper, string $uid, ?string $search): array
    {
        try {
            $tableName = $clientMapper->getTableName();
            $qb        = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName)
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));

            if ($search !== null && $search !== '') {
                $qb->andWhere(
                    $qb->expr()->iLike(
                        'name',
                        $qb->createNamedParameter('%'.$this->db->escapeLikeParameter($search).'%')
                    )
                );
            }

            $qb->orderBy('name', 'ASC');

            $result = $qb->executeQuery();
            $rows   = [];
            $row    = $result->fetch();
            while ($row !== false) {
                $rows[] = (object) $row;
                $row    = $result->fetch();
            }

            $result->closeCursor();
            return $rows;
        } catch (Throwable $e) {
            $this->logger->debug('TimeTrackerLinkService::fetchClientRows failed: '.$e->getMessage());
            return [];
        }//end try
    }//end fetchClientRows()

    /**
     * Reconcile every persisted link row's denormalised entry metadata
     * (name, duration, billable, started_at) against the authoritative
     * NC TimeManager row.
     *
     * Used by the `openregister:time:reconcile` occ command — exposes
     * the same refresh path the on-read drift-guard uses, but driven
     * for every link irrespective of staleness so totals can be
     * recomputed off-line (per the integration-time-tracker spec's
     * "denormalised totals" requirement).
     *
     * Each link is refreshed using its own `linkedBy` user id so the
     * upstream user-scoped TimeManager lookup stays correct even when
     * the command runs without an active user session (e.g. cron).
     *
     * @param string|null $objectUuid Optional object uuid scope. Null walks every row.
     * @param bool        $dryRun     When true, no DB writes happen; counts are returned regardless.
     *
     * @return array{walked: int, refreshed: int, missing: int, errors: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The dry-run toggle is the conventional shape for occ commands.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md
     */
    public function reconcileAllLinks(?string $objectUuid=null, bool $dryRun=false): array
    {
        $stats = [
            'walked'    => 0,
            'refreshed' => 0,
            'missing'   => 0,
            'errors'    => 0,
        ];

        if ($this->isTimeManagerAvailable() === false) {
            return $stats;
        }

        try {
            $links = $this->timeTrackerLinkMapper->findAll($objectUuid);
        } catch (Throwable $e) {
            $this->logger->error('TimeTrackerLinkService::reconcileAllLinks findAll failed: '.$e->getMessage());
            $stats['errors'] = 1;
            return $stats;
        }

        foreach ($links as $link) {
            $stats['walked']++;
            $uid = (string) $link->getLinkedBy();
            if ($uid === '') {
                $stats['missing']++;
                continue;
            }

            $info = $this->fetchEntryInfo(
                entryType: (string) $link->getEntryType(),
                id: $this->entryId(link: $link),
                uid: $uid
            );
            if ($info === null) {
                $stats['missing']++;
                continue;
            }

            $changed = $this->applyRefreshedInfo(link: $link, info: $info);
            if ($changed === false) {
                continue;
            }

            $stats['refreshed']++;

            if ($dryRun === true) {
                continue;
            }

            $link->setLinkedAt(new DateTime());
            try {
                $this->timeTrackerLinkMapper->update($link);
            } catch (Throwable $e) {
                $this->logger->error(
                    'TimeTrackerLinkService::reconcileAllLinks update failed for link id '
                    .((string) $link->getId()).': '.$e->getMessage()
                );
                $stats['errors']++;
            }
        }//end foreach

        return $stats;
    }//end reconcileAllLinks()

    /**
     * Apply refreshed upstream metadata onto a link row. Returns whether
     * any of the denormalised fields actually changed (so the reconcile
     * stats can distinguish "in sync" from "rewritten").
     *
     * @param TimeTrackerLink     $link The link row.
     * @param array<string,mixed> $info Normalised upstream info.
     *
     * @return bool
     */
    private function applyRefreshedInfo(TimeTrackerLink $link, array $info): bool
    {
        $changed = false;

        $name = (string) ($info['name'] ?? '');
        if ($name !== '' && $name !== (string) $link->getName()) {
            $link->setName($name);
            $changed = true;
        }

        $duration = $info['duration'] ?? null;
        if ($duration !== null && (int) $duration !== (int) $link->getDuration()) {
            $link->setDuration((int) $duration);
            $changed = true;
        }

        $billable = $info['billable'] ?? null;
        if ($billable !== null && (bool) $billable !== (bool) $link->getBillable()) {
            $link->setBillable((bool) $billable);
            $changed = true;
        }

        $startedAt = $info['startedAt'] ?? null;
        if ($startedAt instanceof DateTime
            && ($link->getStartedAt() === null
            || $startedAt->getTimestamp() !== $link->getStartedAt()->getTimestamp())
        ) {
            $link->setStartedAt($startedAt);
            $changed = true;
        }

        return $changed;
    }//end applyRefreshedInfo()

    /**
     * Whether a link row's cache is older than the stale window.
     *
     * @param TimeTrackerLink $link The link row.
     *
     * @return bool
     */
    private function isStale(TimeTrackerLink $link): bool
    {
        $linkedAt = $link->getLinkedAt();
        if ($linkedAt === null) {
            return true;
        }

        return (time() - $linkedAt->getTimestamp()) > self::STALE_AFTER;
    }//end isStale()

    /**
     * Refresh a link row's cached entry metadata in place.
     *
     * Best-effort: when the entry can't be resolved the link is left
     * untouched (it may have been deleted in NC TimeManager).
     *
     * @param TimeTrackerLink $link The link row.
     * @param string          $uid  The owning user id.
     *
     * @return TimeTrackerLink The (possibly updated) link row.
     */
    private function refreshLink(TimeTrackerLink $link, string $uid): TimeTrackerLink
    {
        $info = $this->fetchEntryInfo(
            entryType: (string) $link->getEntryType(),
            id: $this->entryId(link: $link),
            uid: $uid
        );
        if ($info === null) {
            return $link;
        }

        if (($info['name'] ?? '') !== '') {
            $link->setName((string) $info['name']);
        }

        $link->setDuration($info['duration'] ?? $link->getDuration());
        $link->setBillable($info['billable'] ?? $link->getBillable());
        $link->setStartedAt($info['startedAt'] ?? $link->getStartedAt());
        $link->setLinkedAt(new DateTime());

        try {
            return $this->timeTrackerLinkMapper->update($link);
        } catch (Throwable $e) {
            $this->logger->debug('TimeTrackerLinkService::refreshLink update failed: '.$e->getMessage());
            return $link;
        }
    }//end refreshLink()

    /**
     * Fetch normalised entry metadata from NC TimeManager.
     *
     * Resolves against the `timemanager_client` / `timemanager_task` /
     * `timemanager_time` tables (via the mapper's table-name accessor)
     * for the active user, normalising into the shape used by
     * hydrateLink / refreshLink.
     *
     * @param string $entryType Entry kind.
     * @param string $id        Upstream entry uuid.
     * @param string $uid       The owning user id.
     *
     * @return array<string,mixed>|null
     */
    private function fetchEntryInfo(string $entryType, string $id, string $uid): ?array
    {
        if ($entryType === self::ENTRY_CLIENT) {
            return $this->fetchByUuid(
                mapper: $this->resolveMapper(class: self::CLIENT_MAPPER),
                uuid: $id,
                uid: $uid,
                table: 'timemanager_client'
            );
        }

        if ($entryType === self::ENTRY_TASK) {
            return $this->fetchByUuid(
                mapper: $this->resolveMapper(class: self::TASK_MAPPER),
                uuid: $id,
                uid: $uid,
                table: 'timemanager_task'
            );
        }

        // Time entries: no dedicated mapper exposed; scan the table by uuid.
        return $this->fetchTimeRow(uuid: $id, uid: $uid);
    }//end fetchEntryInfo()

    /**
     * Fetch a single upstream row by uuid + user via a mapper's table.
     *
     * @param object|null $mapper The late-bound mapper (or null when absent).
     * @param string      $uuid   The entry uuid.
     * @param string      $uid    The owning user id.
     * @param string      $table  Fallback table name when no mapper.
     *
     * @return array<string,mixed>|null
     */
    private function fetchByUuid(?object $mapper, string $uuid, string $uid, string $table): ?array
    {
        $tableName = $table;
        if ($mapper !== null) {
            try {
                $tableName = $mapper->getTableName();
            } catch (Throwable $e) {
                $tableName = $table;
            }
        }

        $row = $this->queryRowByUuid(table: $tableName, uuid: $uuid, uid: $uid);
        if ($row === null) {
            return null;
        }

        return $this->normaliseRow(row: $row);
    }//end fetchByUuid()

    /**
     * Fetch a time-entry row by uuid + user.
     *
     * @param string $uuid The time-entry uuid.
     * @param string $uid  The owning user id.
     *
     * @return array<string,mixed>|null
     */
    private function fetchTimeRow(string $uuid, string $uid): ?array
    {
        $row = $this->queryRowByUuid(table: 'timemanager_time', uuid: $uuid, uid: $uid);
        if ($row === null) {
            return null;
        }

        return $this->normaliseRow(row: $row);
    }//end fetchTimeRow()

    /**
     * Run a uuid + user_id lookup against an upstream table.
     *
     * @param string $table The table name.
     * @param string $uuid  The entry uuid.
     * @param string $uid   The owning user id.
     *
     * @return object|null The row, or null.
     */
    private function queryRowByUuid(string $table, string $uuid, string $uid): ?object
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($table)
                ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return null;
            }

            return (object) $row;
        } catch (Throwable $e) {
            $this->logger->debug('TimeTrackerLinkService::queryRowByUuid failed: '.$e->getMessage());
            return null;
        }//end try
    }//end queryRowByUuid()

    /**
     * Normalise a raw upstream row into the entry-info shape.
     *
     * @param object $row Raw upstream row.
     *
     * @return array<string,mixed>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) normaliseRow() maps four fields (name/duration/billable/startedAt)
     * from NC TimeManager entity objects whose property names differ across client/task/time entry types
     * (`payed` vs `billable`, `start` vs `started_at`); each alternative is required for backward-compatibility
     * with TimeManager's schema evolution.
     */
    private function normaliseRow(object $row): array
    {
        $name = '';
        if (isset($row->name) === true) {
            $name = (string) $row->name;
        }

        $duration = null;
        if (isset($row->duration) === true && $row->duration !== '' && $row->duration !== null) {
            $duration = (int) $row->duration;
        }

        $billable = null;
        if (isset($row->payed) === true) {
            $billable = ((int) $row->payed) === 0;
        } else if (isset($row->billable) === true) {
            $billable = (bool) $row->billable;
        }

        $startedAt = null;
        if (isset($row->start) === true && (string) $row->start !== '') {
            try {
                $startedAt = new DateTime((string) $row->start);
            } catch (Throwable $e) {
                $startedAt = null;
            }
        }

        return [
            'name'      => $name,
            'duration'  => $duration,
            'billable'  => $billable,
            'startedAt' => $startedAt,
        ];
    }//end normaliseRow()

    /**
     * Build the NC TimeManager deep link for an entry.
     *
     * @param string $entryType Entry kind.
     * @param string $id        Upstream entry uuid.
     *
     * @return string
     */
    private function entryDeepLink(string $entryType, string $id): string
    {
        switch ($entryType) {
            case self::ENTRY_TASK:
                return '/index.php/apps/timemanager/tasks/'.$id;
            case self::ENTRY_TIME:
                return '/index.php/apps/timemanager/times/'.$id;
            default:
                return '/index.php/apps/timemanager/clients/'.$id;
        }
    }//end entryDeepLink()

    /**
     * Generate a v4 uuid for new TimeManager rows.
     *
     * @return string
     */
    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end generateUuid()
}//end class
