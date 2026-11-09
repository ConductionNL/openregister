<?php

/**
 * TimeTrackerLinkService — Tier-2 time-tracker (NC TimeManager)
 * integration service.
 *
 * Composes the {@see TimeTrackerLinkMapper} with NC TimeManager's
 * `OCA\TimeManager\Db\ClientMapper` + `TaskMapper` to expose the Tier-2
 * surface:
 *
 *   - linkEntry(target, entryType, id)
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
use OCA\OpenRegister\Db\TimeTrackerLinkMapperInterface;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
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
class TimeTrackerLinkService implements TimeTrackerLinkServiceInterface {
	private const REQUIRED_APP = 'timemanager';

	private const CLIENT_MAPPER = 'OCA\\TimeManager\\Db\\ClientMapper';

	private const TASK_MAPPER = 'OCA\\TimeManager\\Db\\TaskMapper';

	private const ENTRY_CLIENT = 'client';

	private const ENTRY_TASK = 'task';

	private const ENTRY_TIME = 'time';

	private const STALE_AFTER = 86400;
	// 24 hours in seconds.

	/**
	 * Where each mapper-backed entry kind lives: [mapper class, fallback table]. Time
	 * entries have no dedicated mapper and are read from `timemanager_time` directly.
	 */
	private const ENTRY_SOURCES = [
		self::ENTRY_CLIENT => [self::CLIENT_MAPPER, 'timemanager_client'],
		self::ENTRY_TASK => [self::TASK_MAPPER, 'timemanager_task'],
	];

	/**
	 * The id column each entry kind fills; any other kind fills `time_id`.
	 */
	private const ID_COLUMNS = [
		self::ENTRY_CLIENT => 'client_id',
		self::ENTRY_TASK => 'task_id',
	];

	/**
	 * The reconcile counters before the walk.
	 */
	private const RECONCILE_STATS = [
		'walked' => 0,
		'refreshed' => 0,
		'missing' => 0,
		'errors' => 0,
	];

	/**
	 * Constructor.
	 *
	 * @param TimeTrackerLinkMapperInterface $timeTrackerLinkMapper Persistence for link rows.
	 * @param IDBConnection $db DB connection for upstream client/task lookups.
	 * @param ContainerInterface $container Container for late-bound TimeManager classes.
	 * @param LinkServiceRuntime $runtime Whether the app is enabled, the current user, and the logger.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) $timeTrackerLinkMapper follows the repo naming convention for OR
	 * link-table mappers; $timeTrackerLinkMapper is the exact constructor-injected variable name used across
	 * the Tier-2 service pattern and abbreviating it would obscure its purpose.
	 */
	public function __construct(
		private readonly TimeTrackerLinkMapperInterface $timeTrackerLinkMapper,
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
		private readonly LinkServiceRuntime $runtime,
	) {
	}//end __construct()

	/**
	 * Whether NC TimeManager is installed + enabled for the current user.
	 *
	 * @return bool
	 */
	public function isTimeManagerAvailable(): bool {
		return $this->runtime->appManager()->isEnabledForUser(self::REQUIRED_APP);
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
	private function resolveMapper(string $class): ?object {
		if ($this->isTimeManagerAvailable() === false || class_exists($class) === false) {
			return null;
		}

		try {
			return $this->container->get($class);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('TimeTrackerLinkService: ' . $class . ' unavailable: ' . $e->getMessage());
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
	private function requireUid(): string {
		return $this->runtime->userSession()->getUser()?->getUID() ?? throw new Exception('No user logged in', 401);
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
	private function normaliseEntryType(string $entryType): string {
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
	 * @param LinkTarget $target    The object to link to.
	 * @param string     $entryType Entry kind (`client`|`task`|`time`).
	 * @param string     $id        Upstream entry uuid.
	 *
	 * @return TimeTrackerLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), bad type (400), missing
	 *                   entry (404), duplicate (409), TimeManager
	 *                   unavailable (503).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the link-entry contract is owned by the integration-time-tracker capability.
	 */
	public function linkEntry(LinkTarget $target, string $entryType, string $id): TimeTrackerLink {
		$uid       = $this->requireUid();
		$entryType = $this->normaliseEntryType(entryType: $entryType);
		$id        = $this->requireEntryId(id: $id);
		$this->assertEntryUnlinked(target: $target, key: $this->entryLink(entryType: $entryType, id: $id));

		$info = $this->fetchEntryInfo(entryType: $entryType, id: $id, uid: $uid);
		if ($info === null) {
			throw new Exception('TimeManager entry not found', 404);
		}

		return $this->persistLink(target: $target, uid: $uid, link: $this->hydrateLink(entryType: $entryType, id: $id, info: $info));
	}//end linkEntry()

	/**
	 * Stamp a new link row with its object and linking user, and insert it.
	 *
	 * @param LinkTarget      $target The object to link to.
	 * @param string          $uid    The linking user.
	 * @param TimeTrackerLink $link   The unsaved row.
	 *
	 * @return TimeTrackerLink The persisted link row.
	 */
	private function persistLink(LinkTarget $target, string $uid, TimeTrackerLink $link): TimeTrackerLink {
		$target->stamp(link: $link, linkedBy: $uid);

		return $this->timeTrackerLinkMapper->insert($link);
	}//end persistLink()

	/**
	 * The trimmed entry id, once TimeManager is known to be available.
	 *
	 * @param string $id The raw upstream entry uuid.
	 *
	 * @return string The trimmed id.
	 *
	 * @throws Exception On an empty id (400) or TimeManager unavailable (503).
	 */
	private function requireEntryId(string $id): string {
		$id = trim($id);
		if ($id === '') {
			throw new Exception('Entry id is required', 400);
		}

		if ($this->isTimeManagerAvailable() === false) {
			throw new Exception('NC TimeManager is not available', 503);
		}

		return $id;
	}//end requireEntryId()

	/**
	 * Refuse a link when the entry is already linked to the object.
	 *
	 * @param LinkTarget      $target The object to link to.
	 * @param TimeTrackerLink $key    An unsaved row carrying the entry type and ids.
	 *
	 * @return void
	 *
	 * @throws Exception On a duplicate (409).
	 */
	private function assertEntryUnlinked(LinkTarget $target, TimeTrackerLink $key): void {
		$existing = $this->timeTrackerLinkMapper->findByObjectAndEntry(objectUuid: $target->objectUuid, entry: $key);
		if ($existing !== null) {
			throw new Exception('Entry already linked to this object', 409);
		}
	}//end assertEntryUnlinked()

	/**
	 * Create a new NC TimeManager client and link it to an OR object.
	 *
	 * @param LinkTarget $target The object to link to.
	 * @param string $name New client name.
	 *
	 * @return TimeTrackerLink The persisted link row.
	 *
	 * @throws Exception On missing user (401), empty name (400),
	 *                   TimeManager unavailable (503), create failure (500).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the create-and-link-client contract is
	 *       owned by the integration-time-tracker capability.
	 */
	public function createAndLinkClient(LinkTarget $target, string $name): TimeTrackerLink {
		$uid  = $this->requireUid();
		$name = trim($name);
		if ($name === '') {
			throw new Exception('Client name is required', 400);
		}

		$clientUuid = $this->createClient(name: $name, uid: $uid);
		$info       = ['name' => $name, 'duration' => null, 'billable' => null, 'startedAt' => null];

		return $this->persistLink(target: $target, uid: $uid, link: $this->hydrateLink(entryType: self::ENTRY_CLIENT, id: $clientUuid, info: $info));
	}//end createAndLinkClient()

	/**
	 * Create a TimeManager client for the user and return its uuid.
	 *
	 * @param string $name The client name (trimmed, non-empty).
	 * @param string $uid  The owning user id.
	 *
	 * @return string The new client uuid.
	 *
	 * @throws Exception When TimeManager is unavailable (503) or the create fails (500).
	 */
	private function createClient(string $name, string $uid): string {
		$clientMapper = $this->resolveMapper(class: self::CLIENT_MAPPER) ?? throw new Exception('NC TimeManager is not available', 503);
		try {
			return $this->insertClient(clientMapper: $clientMapper, name: $name, uid: $uid);
		} catch (Throwable $e) {
			$this->runtime->logger()->warning('TimeTrackerLinkService::createAndLinkClient failed: ' . $e->getMessage());
			throw new Exception('Failed to create TimeManager client', 500);
		}
	}//end createClient()

	/**
	 * Insert a new TimeManager client row and return its uuid.
	 *
	 * TimeManager's ClientMapper extends the shared ObjectMapper whose
	 * `insert()` expects a populated entity; we build the entity via the
	 * mapper's entity class so this stays decoupled from the upstream
	 * concrete class at compile time.
	 *
	 * @param object $clientMapper The late-bound TimeManager ClientMapper.
	 * @param string $name The client name.
	 * @param string $uid The owning user id.
	 *
	 * @return string The new client uuid.
	 *
	 * @throws Throwable On any upstream failure.
	 */
	private function insertClient(object $clientMapper, string $name, string $uid): string {
		$uuid      = $this->generateUuid();
		$tableName = $clientMapper->getTableName();

		$qb = $this->db->getQueryBuilder();
		$qb->insert($tableName)->values($this->namedParameters(qb: $qb, values: $this->clientColumns(name: $name, uid: $uid, uuid: $uuid)));
		$qb->executeStatement();

		return $uuid;
	}//end insertClient()

	/**
	 * The column values of a new TimeManager client row.
	 *
	 * @param string $name The client name.
	 * @param string $uid  The owning user id.
	 * @param string $uuid The new client uuid.
	 *
	 * @return array<string,string>
	 */
	private function clientColumns(string $name, string $uid, string $uuid): array {
		$now = (new DateTime())->format('Y-m-d\TH:i:sP');

		return ['name' => $name, 'user_id' => $uid, 'uuid' => $uuid, 'created' => $now, 'changed' => $now, 'commit' => ''];
	}//end clientColumns()

	/**
	 * A named string parameter per column value, in column order.
	 *
	 * @param IQueryBuilder        $qb     The query being built.
	 * @param array<string,string> $values Column => value.
	 *
	 * @return array<string,mixed>
	 */
	private function namedParameters(IQueryBuilder $qb, array $values): array {
		$params = [];
		foreach ($values as $column => $value) {
			$params[$column] = $qb->createNamedParameter($value);
		}

		return $params;
	}//end namedParameters()

	/**
	 * Build a TimeTrackerLink from normalised entry info.
	 *
	 * @param string $entryType Entry kind.
	 * @param string $id Upstream entry uuid.
	 * @param array<string,mixed> $info Normalised entry info.
	 *
	 * @return TimeTrackerLink
	 */
	private function hydrateLink(string $entryType, string $id, array $info): TimeTrackerLink {
		$link = $this->entryLink(entryType: $entryType, id: $id);
		$link->setName((string)($info['name'] ?? ''));
		$link->setDuration($info['duration'] ?? null);
		$link->setBillable($info['billable'] ?? null);
		$link->setStartedAt($info['startedAt'] ?? null);

		return $link;
	}//end hydrateLink()

	/**
	 * An unsaved link row carrying only the entry type and its id columns.
	 *
	 * @param string $entryType Entry kind.
	 * @param string $id        Upstream entry uuid.
	 *
	 * @return TimeTrackerLink
	 */
	private function entryLink(string $entryType, string $id): TimeTrackerLink {
		$components = $this->idComponents(entryType: $entryType, id: $id);

		$link = new TimeTrackerLink();
		$link->setEntryType($entryType);
		$link->setClientId($components['client_id']);
		$link->setTaskId($components['task_id']);
		$link->setTimeId($components['time_id']);

		return $link;
	}//end entryLink()

	/**
	 * Map an entry id into the three id columns per entry type.
	 *
	 * @param string $entryType Entry kind.
	 * @param string $id Upstream entry uuid.
	 *
	 * @return array{client_id:?string,task_id:?string,time_id:?string}
	 */
	private function idComponents(string $entryType, string $id): array {
		$components = ['client_id' => null, 'task_id' => null, 'time_id' => null];
		$components[self::ID_COLUMNS[$entryType] ?? 'time_id'] = $id;

		return $components;
	}//end idComponents()

	/**
	 * Unlink an entry from an object.
	 *
	 * Does NOT delete the upstream entry — it stays in NC TimeManager.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param string $entryId Upstream entry uuid.
	 *
	 * @return void
	 *
	 * @throws Exception On missing user (401) or no matching link (404).
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service facade; the unlink contract is owned by the integration-time-tracker capability.
	 */
	public function unlink(string $objectUuid, string $entryId): void {
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
	public function getLinkedEntries(string $objectUuid): array {
		$links     = $this->timeTrackerLinkMapper->findByObjectUuid($objectUuid);
		$available = $this->isTimeManagerAvailable();
		$uid       = $this->runtime->userSession()->getUser()?->getUID();

		$results = [];
		foreach ($links as $link) {
			$results[] = $this->rowFromLink(link: $this->currentLink(link: $link, available: $available, uid: $uid));
		}

		return $results;
	}//end getLinkedEntries()

	/**
	 * The link, refreshed from TimeManager first when it and a user are there and the cache is stale.
	 *
	 * @param TimeTrackerLink $link      The link row.
	 * @param bool            $available Whether TimeManager is available.
	 * @param string|null     $uid       The current user, if any.
	 *
	 * @return TimeTrackerLink
	 */
	private function currentLink(TimeTrackerLink $link, bool $available, ?string $uid): TimeTrackerLink {
		if ($available === true && $uid !== null && $this->isStale(link: $link) === true) {
			return $this->refreshLink(link: $link, uid: $uid);
		}

		return $link;
	}//end currentLink()

	/**
	 * Convert a TimeTrackerLink into the registry leaf-row shape (the
	 * three-kind shape the bespoke tab + TimeProvider share).
	 *
	 * @param TimeTrackerLink $link Link row.
	 *
	 * @return array<string,mixed>
	 */
	private function rowFromLink(TimeTrackerLink $link): array {
		$entryType = (string)$link->getEntryType();
		$id        = $this->entryId(link: $link);
		$name      = (string)$link->getName();

		$row         = ['id' => $id, 'kind' => $entryType, 'type' => $entryType, 'title' => $name, 'name' => $name];
		$row         = array_merge($row, $this->cachedColumns(link: $link));
		$row['url']  = $this->entryDeepLink(entryType: $entryType, id: $id);
		$row['data'] = $link->jsonSerialize();

		return $row;
	}//end rowFromLink()

	/**
	 * The id columns and cached metadata of a link, in leaf-row form.
	 *
	 * @param TimeTrackerLink $link Link row.
	 *
	 * @return array<string,mixed>
	 */
	private function cachedColumns(TimeTrackerLink $link): array {
		return [
			'clientId' => $link->getClientId(),
			'taskId' => $link->getTaskId(),
			'timeId' => $link->getTimeId(),
			'duration' => $link->getDuration(),
			'billable' => $link->getBillable(),
			'startedAt' => $link->getStartedAt()?->format(DateTime::ATOM),
		];
	}//end cachedColumns()

	/**
	 * The upstream entry id carried by the link, per entry type.
	 *
	 * @param TimeTrackerLink $link Link row.
	 *
	 * @return string
	 */
	private function entryId(TimeTrackerLink $link): string {
		switch ((string)$link->getEntryType()) {
			case self::ENTRY_TASK:
				return (string)$link->getTaskId();
			case self::ENTRY_TIME:
				return (string)$link->getTimeId();
			default:
				return (string)$link->getClientId();
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
	public function getAvailableClients(?string $search = null): array {
		$clientMapper = $this->resolveMapper(class: self::CLIENT_MAPPER);
		$uid          = $this->runtime->userSession()->getUser()?->getUID();
		if ($clientMapper === null || $uid === null) {
			return [];
		}

		return $this->clientPickerRows(rows: $this->fetchClientRows(clientMapper: $clientMapper, uid: $uid, search: $search));
	}//end getAvailableClients()

	/**
	 * Picker rows for the client rows that carry a uuid.
	 *
	 * @param array<int,object> $rows Raw client rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function clientPickerRows(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$entry = $this->clientPickerRow(row: $row);
			if ($entry !== null) {
				$out[] = $entry;
			}
		}

		return $out;
	}//end clientPickerRows()

	/**
	 * A picker row for one client row, or null when it carries no uuid.
	 *
	 * @param object $row Raw client row.
	 *
	 * @return array{id:string,kind:string,name:string,title:string}|null
	 */
	private function clientPickerRow(object $row): ?array {
		$uuid = $this->stringProperty(row: $row, property: 'uuid');
		$name = $this->stringProperty(row: $row, property: 'name');
		if ($uuid === '') {
			return null;
		}

		return ['id' => $uuid, 'kind' => self::ENTRY_CLIENT, 'name' => $name, 'title' => $name];
	}//end clientPickerRow()

	/**
	 * A row property as a string, '' when it is not set.
	 *
	 * @param object $row      Raw upstream row.
	 * @param string $property Property name.
	 *
	 * @return string
	 */
	private function stringProperty(object $row, string $property): string {
		if (isset($row->{$property}) === true) {
			return (string)$row->{$property};
		}

		return '';
	}//end stringProperty()

	/**
	 * Run the LIKE/scan query for the current user's TimeManager clients.
	 *
	 * @param object $clientMapper The late-bound TimeManager ClientMapper.
	 * @param string $uid The owning user id.
	 * @param string|null $search Optional name-substring filter.
	 *
	 * @return array<int,object> Matching rows (loose-typed).
	 */
	private function fetchClientRows(object $clientMapper, string $uid, ?string $search): array {
		try {
			$tableName = $clientMapper->getTableName();
			return $this->fetchObjects(result: $this->clientsQuery(tableName: $tableName, uid: $uid, search: $search)->executeQuery());
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('TimeTrackerLinkService::fetchClientRows failed: ' . $e->getMessage());
			return [];
		}
	}//end fetchClientRows()

	/**
	 * The query for a user's clients, by name, optionally filtered on a name substring.
	 *
	 * @param string      $tableName The client table.
	 * @param string      $uid       The owning user id.
	 * @param string|null $search    Optional name-substring filter.
	 *
	 * @return IQueryBuilder
	 */
	private function clientsQuery(string $tableName, string $uid, ?string $search): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($tableName)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));

		if ($search !== null && $search !== '') {
			$pattern = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->iLike('name', $qb->createNamedParameter($pattern)));
		}

		$qb->orderBy('name', 'ASC');

		return $qb;
	}//end clientsQuery()

	/**
	 * Every row of a result as an object, closing the cursor afterwards.
	 *
	 * @param IResult $result The query result.
	 *
	 * @return array<int,object>
	 */
	private function fetchObjects(IResult $result): array {
		$rows = [];
		$row  = $result->fetch();
		while ($row !== false) {
			$rows[] = (object)$row;
			$row    = $result->fetch();
		}

		$result->closeCursor();
		return $rows;
	}//end fetchObjects()

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
	 * @param bool $dryRun When true, no DB writes happen; counts are returned regardless.
	 *
	 * @return array{walked: int, refreshed: int, missing: int, errors: int}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The dry-run toggle is the conventional shape for occ commands.
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	public function reconcileAllLinks(?string $objectUuid = null, bool $dryRun = false): array {
		if ($this->isTimeManagerAvailable() === false) {
			return self::RECONCILE_STATS;
		}

		$links = $this->reconcileTargets(objectUuid: $objectUuid);
		if ($links === null) {
			return array_merge(self::RECONCILE_STATS, ['errors' => 1]);
		}

		return $this->reconcileLinks(links: $links, dryRun: $dryRun);
	}//end reconcileAllLinks()

	/**
	 * The link rows to reconcile, or null when they cannot be read.
	 *
	 * @param string|null $objectUuid Optional object uuid scope.
	 *
	 * @return iterable<TimeTrackerLink>|null
	 */
	private function reconcileTargets(?string $objectUuid): ?iterable {
		try {
			return $this->timeTrackerLinkMapper->findAll($objectUuid);
		} catch (Throwable $e) {
			$this->runtime->logger()->error('TimeTrackerLinkService::reconcileAllLinks findAll failed: ' . $e->getMessage());
			return null;
		}
	}//end reconcileTargets()

	/**
	 * Reconcile each link and count the outcomes.
	 *
	 * @param iterable<TimeTrackerLink> $links  The link rows.
	 * @param bool                      $dryRun When true, nothing is written.
	 *
	 * @return array{walked: int, refreshed: int, missing: int, errors: int}
	 */
	private function reconcileLinks(iterable $links, bool $dryRun): array {
		$stats = self::RECONCILE_STATS;
		foreach ($links as $link) {
			$stats['walked']++;
			foreach ($this->reconcileLink(link: $link, dryRun: $dryRun) as $counter) {
				$stats[$counter]++;
			}
		}

		return $stats;
	}//end reconcileLinks()

	/**
	 * Reconcile one link against TimeManager as its own linking user.
	 *
	 * @param TimeTrackerLink $link   The link row.
	 * @param bool            $dryRun When true, nothing is written.
	 *
	 * @return array<int,string> The counters this link increments.
	 */
	private function reconcileLink(TimeTrackerLink $link, bool $dryRun): array {
		$info = $this->linkerInfo(link: $link);
		if ($info === null) {
			return ['missing'];
		}

		if ($this->applyRefreshedInfo(link: $link, info: $info) === false) {
			return [];
		}

		if ($dryRun === true) {
			return ['refreshed'];
		}

		return $this->saveReconciled(link: $link);
	}//end reconcileLink()

	/**
	 * The upstream info of a link, looked up as the user who linked it; null when
	 * that user is unknown or the entry is gone.
	 *
	 * @param TimeTrackerLink $link The link row.
	 *
	 * @return array<string,mixed>|null
	 */
	private function linkerInfo(TimeTrackerLink $link): ?array {
		$uid = (string)$link->getLinkedBy();
		if ($uid === '') {
			return null;
		}

		return $this->fetchLinkInfo(link: $link, uid: $uid);
	}//end linkerInfo()

	/**
	 * Persist a reconciled link.
	 *
	 * @param TimeTrackerLink $link The changed link row.
	 *
	 * @return array<int,string> The counters this link increments.
	 */
	private function saveReconciled(TimeTrackerLink $link): array {
		$link->setLinkedAt(new DateTime());
		try {
			$this->timeTrackerLinkMapper->update($link);
			return ['refreshed'];
		} catch (Throwable $e) {
			$this->runtime->logger()->error(
				'TimeTrackerLinkService::reconcileAllLinks update failed for link id ' . ((string)$link->getId()) . ': ' . $e->getMessage()
			);
			return ['refreshed', 'errors'];
		}
	}//end saveReconciled()

	/**
	 * Apply refreshed upstream metadata onto a link row. Returns whether
	 * any of the denormalised fields actually changed (so the reconcile
	 * stats can distinguish "in sync" from "rewritten").
	 *
	 * @param TimeTrackerLink $link The link row.
	 * @param array<string,mixed> $info Normalised upstream info.
	 *
	 * @return bool
	 */
	private function applyRefreshedInfo(TimeTrackerLink $link, array $info): bool {
		$changed = $this->refreshName(link: $link, name: (string)($info['name'] ?? ''));
		$changed = $this->refreshDuration(link: $link, duration: $info['duration'] ?? null) || $changed;
		$changed = $this->refreshBillable(link: $link, billable: $info['billable'] ?? null) || $changed;
		$changed = $this->refreshStartedAt(link: $link, startedAt: $info['startedAt'] ?? null) || $changed;

		return $changed;
	}//end applyRefreshedInfo()

	/**
	 * Take a non-empty upstream name that differs from the cached one.
	 *
	 * @param TimeTrackerLink $link The link row.
	 * @param string          $name Upstream name.
	 *
	 * @return bool Whether it changed.
	 */
	private function refreshName(TimeTrackerLink $link, string $name): bool {
		if ($name === '' || $name === (string)$link->getName()) {
			return false;
		}

		$link->setName($name);
		return true;
	}//end refreshName()

	/**
	 * Take an upstream duration that differs from the cached one.
	 *
	 * @param TimeTrackerLink $link     The link row.
	 * @param mixed           $duration Upstream duration, null when unknown.
	 *
	 * @return bool Whether it changed.
	 */
	private function refreshDuration(TimeTrackerLink $link, mixed $duration): bool {
		if ($duration === null || (int)$duration === (int)$link->getDuration()) {
			return false;
		}

		$link->setDuration((int)$duration);
		return true;
	}//end refreshDuration()

	/**
	 * Take an upstream billable flag that differs from the cached one.
	 *
	 * @param TimeTrackerLink $link     The link row.
	 * @param mixed           $billable Upstream flag, null when unknown.
	 *
	 * @return bool Whether it changed.
	 */
	private function refreshBillable(TimeTrackerLink $link, mixed $billable): bool {
		if ($billable === null || (bool)$billable === (bool)$link->getBillable()) {
			return false;
		}

		$link->setBillable((bool)$billable);
		return true;
	}//end refreshBillable()

	/**
	 * Take an upstream start time that differs from the cached one.
	 *
	 * @param TimeTrackerLink $link      The link row.
	 * @param mixed           $startedAt Upstream start, a DateTime or null.
	 *
	 * @return bool Whether it changed.
	 */
	private function refreshStartedAt(TimeTrackerLink $link, mixed $startedAt): bool {
		if ($startedAt instanceof DateTime === false) {
			return false;
		}

		$cached = $link->getStartedAt();
		if ($cached !== null && $startedAt->getTimestamp() === $cached->getTimestamp()) {
			return false;
		}

		$link->setStartedAt($startedAt);
		return true;
	}//end refreshStartedAt()

	/**
	 * Whether a link row's cache is older than the stale window.
	 *
	 * @param TimeTrackerLink $link The link row.
	 *
	 * @return bool
	 */
	private function isStale(TimeTrackerLink $link): bool {
		$linkedAt = $link->getLinkedAt();

		return $linkedAt === null || (time() - $linkedAt->getTimestamp()) > self::STALE_AFTER;
	}//end isStale()

	/**
	 * Refresh a link row's cached entry metadata in place.
	 *
	 * Best-effort: when the entry can't be resolved the link is left
	 * untouched (it may have been deleted in NC TimeManager).
	 *
	 * @param TimeTrackerLink $link The link row.
	 * @param string $uid The owning user id.
	 *
	 * @return TimeTrackerLink The (possibly updated) link row.
	 */
	private function refreshLink(TimeTrackerLink $link, string $uid): TimeTrackerLink {
		$info = $this->fetchLinkInfo(link: $link, uid: $uid);
		if ($info === null) {
			return $link;
		}

		$this->applyCachedInfo(link: $link, info: $info);
		$link->setLinkedAt(new DateTime());

		try {
			return $this->timeTrackerLinkMapper->update($link);
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('TimeTrackerLinkService::refreshLink update failed: ' . $e->getMessage());
			return $link;
		}
	}//end refreshLink()

	/**
	 * The upstream info of a link's entry, looked up as the given user.
	 *
	 * @param TimeTrackerLink $link The link row.
	 * @param string          $uid  The user to look up as.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchLinkInfo(TimeTrackerLink $link, string $uid): ?array {
		return $this->fetchEntryInfo(entryType: (string)$link->getEntryType(), id: $this->entryId(link: $link), uid: $uid);
	}//end fetchLinkInfo()

	/**
	 * Overwrite the cached metadata with upstream info; a missing name or value keeps the cached one.
	 *
	 * @param TimeTrackerLink     $link The link row.
	 * @param array<string,mixed> $info Normalised upstream info.
	 *
	 * @return void
	 */
	private function applyCachedInfo(TimeTrackerLink $link, array $info): void {
		if (($info['name'] ?? '') !== '') {
			$link->setName((string)$info['name']);
		}

		$link->setDuration($info['duration'] ?? $link->getDuration());
		$link->setBillable($info['billable'] ?? $link->getBillable());
		$link->setStartedAt($info['startedAt'] ?? $link->getStartedAt());
	}//end applyCachedInfo()

	/**
	 * Fetch normalised entry metadata from NC TimeManager.
	 *
	 * Resolves against the `timemanager_client` / `timemanager_task` /
	 * `timemanager_time` tables (via the mapper's table-name accessor)
	 * for the active user, normalising into the shape used by
	 * hydrateLink / refreshLink.
	 *
	 * @param string $entryType Entry kind.
	 * @param string $id Upstream entry uuid.
	 * @param string $uid The owning user id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchEntryInfo(string $entryType, string $id, string $uid): ?array {
		$source = self::ENTRY_SOURCES[$entryType] ?? null;
		if ($source === null) {
			// Time entries: no dedicated mapper exposed; scan the table by uuid.
			return $this->fetchTimeRow(uuid: $id, uid: $uid);
		}

		return $this->fetchByUuid(mapper: $this->resolveMapper(class: $source[0]), uuid: $id, uid: $uid, table: $source[1]);
	}//end fetchEntryInfo()

	/**
	 * Fetch a single upstream row by uuid + user via a mapper's table.
	 *
	 * @param object|null $mapper The late-bound mapper (or null when absent).
	 * @param string $uuid The entry uuid.
	 * @param string $uid The owning user id.
	 * @param string $table Fallback table name when no mapper.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchByUuid(?object $mapper, string $uuid, string $uid, string $table): ?array {
		return $this->fetchRow(table: $this->tableOf(mapper: $mapper, fallback: $table), uuid: $uuid, uid: $uid);
	}//end fetchByUuid()

	/**
	 * A mapper's table name, or the fallback when there is no mapper or it cannot say.
	 *
	 * @param object|null $mapper   The late-bound mapper (or null when absent).
	 * @param string      $fallback Fallback table name.
	 *
	 * @return string
	 */
	private function tableOf(?object $mapper, string $fallback): string {
		if ($mapper === null) {
			return $fallback;
		}

		try {
			return $mapper->getTableName();
		} catch (Throwable $e) {
			return $fallback;
		}
	}//end tableOf()

	/**
	 * Fetch and normalise one upstream row by uuid + user.
	 *
	 * @param string $table The table name.
	 * @param string $uuid  The entry uuid.
	 * @param string $uid   The owning user id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchRow(string $table, string $uuid, string $uid): ?array {
		$row = $this->queryRowByUuid(table: $table, uuid: $uuid, uid: $uid);
		if ($row === null) {
			return null;
		}

		return $this->normaliseRow(row: $row);
	}//end fetchRow()

	/**
	 * Fetch a time-entry row by uuid + user.
	 *
	 * @param string $uuid The time-entry uuid.
	 * @param string $uid The owning user id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchTimeRow(string $uuid, string $uid): ?array {
		return $this->fetchRow(table: 'timemanager_time', uuid: $uuid, uid: $uid);
	}//end fetchTimeRow()

	/**
	 * Run a uuid + user_id lookup against an upstream table.
	 *
	 * @param string $table The table name.
	 * @param string $uuid The entry uuid.
	 * @param string $uid The owning user id.
	 *
	 * @return object|null The row, or null.
	 */
	private function queryRowByUuid(string $table, string $uuid, string $uid): ?object {
		try {
			$result = $this->rowByUuidQuery(table: $table, uuid: $uuid, uid: $uid)->executeQuery();
			$row    = $result->fetch();
			$result->closeCursor();

			if ($row === false) {
				return null;
			}

			return (object)$row;
		} catch (Throwable $e) {
			$this->runtime->logger()->debug('TimeTrackerLinkService::queryRowByUuid failed: ' . $e->getMessage());
			return null;
		}
	}//end queryRowByUuid()

	/**
	 * The uuid + user_id lookup query against an upstream table.
	 *
	 * @param string $table The table name.
	 * @param string $uuid  The entry uuid.
	 * @param string $uid   The owning user id.
	 *
	 * @return IQueryBuilder
	 */
	private function rowByUuidQuery(string $table, string $uuid, string $uid): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($table)
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->setMaxResults(1);

		return $qb;
	}//end rowByUuidQuery()

	/**
	 * Normalise a raw upstream row into the entry-info shape.
	 *
	 * @param object $row Raw upstream row.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseRow(object $row): array {
		return [
			'name' => $this->stringProperty(row: $row, property: 'name'),
			'duration' => $this->durationOf(row: $row),
			'billable' => $this->billableOf(row: $row),
			'startedAt' => $this->startedAtOf(row: $row),
		];
	}//end normaliseRow()

	/**
	 * A row's duration, null when unset or empty.
	 *
	 * @param object $row Raw upstream row.
	 *
	 * @return int|null
	 */
	private function durationOf(object $row): ?int {
		if (isset($row->duration) === true && $row->duration !== '') {
			return (int)$row->duration;
		}

		return null;
	}//end durationOf()

	/**
	 * A row's billable flag: TimeManager's `payed` (0 means billable), else `billable`, else null.
	 *
	 * @param object $row Raw upstream row.
	 *
	 * @return bool|null
	 */
	private function billableOf(object $row): ?bool {
		if (isset($row->payed) === true) {
			return ((int)$row->payed) === 0;
		}

		if (isset($row->billable) === true) {
			return (bool)$row->billable;
		}

		return null;
	}//end billableOf()

	/**
	 * A row's start time, null when unset, empty or unreadable.
	 *
	 * @param object $row Raw upstream row.
	 *
	 * @return DateTime|null
	 */
	private function startedAtOf(object $row): ?DateTime {
		if (isset($row->start) === false || (string)$row->start === '') {
			return null;
		}

		try {
			return new DateTime((string)$row->start);
		} catch (Throwable $e) {
			return null;
		}
	}//end startedAtOf()

	/**
	 * Build the NC TimeManager deep link for an entry.
	 *
	 * @param string $entryType Entry kind.
	 * @param string $id Upstream entry uuid.
	 *
	 * @return string
	 */
	private function entryDeepLink(string $entryType, string $id): string {
		switch ($entryType) {
			case self::ENTRY_TASK:
				return '/index.php/apps/timemanager/tasks/' . $id;
			case self::ENTRY_TIME:
				return '/index.php/apps/timemanager/times/' . $id;
			default:
				return '/index.php/apps/timemanager/clients/' . $id;
		}
	}//end entryDeepLink()

	/**
	 * Generate a v4 uuid for new TimeManager rows.
	 *
	 * @return string
	 */
	private function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end generateUuid()
}//end class
