<?php

/**
 * OpenRegister ScheduledNotificationJob
 *
 * 60s TimedJob that fires `x-openregister-notifications` entries whose
 * trigger.type === 'scheduled'. Each entry has a `trigger.intervalSec`
 * (>= 60) that controls how often it fires.
 *
 * For each due notification, the job iterates the schema's objects
 * (optionally filtered by `trigger.filter`) and calls the existing
 * AnnotationNotificationDispatcher with trigger='scheduled'. All
 * channel logic (nc-notification, email, activity, webhook, talk) is
 * reused unchanged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Scheduled notification background job.
 *
 * @psalm-suppress UnusedClass
 */
final class ScheduledNotificationJob extends TimedJob {

	/**
	 * Hard upper bound on the number of objects scanned per (schema, notification)
	 * fire. Acts as a memory/time guard so a single huge schema cannot OOM or stall
	 * the cron run (OPS-6 / PERF-3). When the cap is hit a warning is logged.
	 *
	 * TODO(PERF-3): push the trigger `filter` into SQL (a paged findBySchema with
	 * _filter+_limit in lib/Db/MagicMapper) and add a per-schema watermark for
	 * delta scans, so we no longer load the whole table into PHP and filter
	 * in-memory. Until then this cap bounds the blast radius.
	 *
	 * The pushdown input is the AST from `ScheduledFilterParser`, not the raw
	 * annotation: every operator is a single-column predicate and `all` / `any`
	 * are `AND` / `OR`, so the grammar translates to SQL directly. Two
	 * constraints hold whatever a compiler does with it — reference instants
	 * (`now`, signed durations) must be resolved against the scan's single
	 * `$now` BEFORE compilation, or two rows in one pass can be judged against
	 * different clocks; and a partial pushdown must select a superset that the
	 * in-memory walk then narrows, so that pushing down more never changes which
	 * objects a rule selects.
	 */
	private const MAX_OBJECTS_PER_FIRE = 5000;

	/**
	 * Distributed cache holding last-fire timestamps per (schema, notification).
	 *
	 * @var ICache|null
	 */
	private ?ICache $stateCache = null;

	/**
	 * Schema lookup mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Magic object mapper.
	 *
	 * @var MagicObjectFinderInterface
	 */
	private readonly MagicObjectFinderInterface $objectMapper;

	/**
	 * Operator-aware filter evaluator.
	 *
	 * @var ScheduledFilterEvaluator
	 */
	private readonly ScheduledFilterEvaluator $filterEvaluator;

	/**
	 * Notification dispatcher.
	 *
	 * @var AnnotationNotificationDispatcher
	 */
	private readonly AnnotationNotificationDispatcher $dispatcher;

	/**
	 * Per-object dedup state mapper.
	 *
	 * @var NotificationDedupeStateMapper
	 */
	private readonly NotificationDedupeStateMapper $dedupeMapper;

	/**
	 * App config for tunable retention window.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * PSR logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Wire collaborators and configure the timed-job interval.
	 *
	 * @param ITimeFactory                  $time         Nextcloud time factory.
	 * @param ScheduledObjectSources        $sources      Schemas, objects and the filter evaluator.
	 * @param ScheduledNotificationDelivery $delivery     Dispatcher, dedup state, app config and logger.
	 * @param ICacheFactory                 $cacheFactory Distributed cache factory.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		ScheduledObjectSources $sources,
		ScheduledNotificationDelivery $delivery,
		ICacheFactory $cacheFactory,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 60);
		$this->schemaMapper = $sources->schemaMapper;
		$this->objectMapper = $sources->objectMapper;
		$this->filterEvaluator = $sources->filterEvaluator;
		$this->dispatcher = $delivery->dispatcher;
		$this->dedupeMapper = $delivery->dedupeMapper;
		$this->appConfig = $delivery->appConfig;
		$this->logger = $delivery->logger;

		try {
			$this->stateCache = $cacheFactory->createDistributed('openregister_scheduled_notifs');
		} catch (\Throwable $e) {
			$this->stateCache = null;
		}
	}//end __construct()

	/**
	 * Iterate every schema and fire any due scheduled notifications.
	 *
	 * @param mixed $argument Background-job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	protected function run($argument): void {
		// One logical "now" per scan pass so every entry sees the same window
		// (Phase 1 — filter operator evaluator).
		$nowDt   = (new DateTimeImmutable('@'.time()))->setTimezone(new DateTimeZone('UTC'));
		$schemas = $this->loadSchemas();
		if ($schemas === null) {
			return;
		}

		foreach ($schemas as $schema) {
			if (($schema instanceof Schema) === true) {
				$this->processSchema(schema: $schema, nowDt: $nowDt);
			}
		}

		// Retention sweep — once per scan pass, best-effort. Drop dedup rows
		// last seen before the configured cutoff so that an object that no
		// longer matches any rule (purged / archived / annotation removed)
		// does not pile up state forever.
		$this->runRetentionSweep(nowDt: $nowDt);
	}//end run()

	/**
	 * Every schema; null (with a warning) when the list cannot be read.
	 *
	 * @return array|null
	 */
	private function loadSchemas(): ?array {
		try {
			return $this->schemaMapper->findAll();
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('[ScheduledNotificationJob] schema list failed: %s', $e->getMessage()));
			return null;
		}
	}//end loadSchemas()

	/**
	 * Drop dedup rows whose `seen_at` is older than the configured retention.
	 *
	 * @param DateTimeImmutable $nowDt Logical "now" for this scan pass.
	 *
	 * @return void
	 */
	private function runRetentionSweep(DateTimeImmutable $nowDt): void {
		$days = $this->retentionDays();
		if ($days <= 0) {
			return;
		}

		$cutoff = DateTime::createFromImmutable($nowDt);
		$cutoff->modify(sprintf('-%d days', $days));

		$this->dedupeMapper->deleteSeenBefore(cutoff: $cutoff);
	}//end runRetentionSweep()

	/**
	 * Configured dedupe retention in days; the mapper's default when unreadable.
	 *
	 * @return int
	 */
	private function retentionDays(): int {
		$default = (string)NotificationDedupeStateMapper::DEFAULT_RETENTION_DAYS;
		try {
			return (int)$this->appConfig->getValueString('openregister', 'notification_dedupe_retention_days', $default);
		} catch (\Throwable $e) {
			return NotificationDedupeStateMapper::DEFAULT_RETENTION_DAYS;
		}
	}//end retentionDays()

	/**
	 * Fire every due scheduled rule declared on a schema.
	 *
	 * @param Schema            $schema Schema to scan
	 * @param DateTimeImmutable $nowDt  Scan time, in UTC
	 *
	 * @return void
	 */
	private function processSchema(Schema $schema, DateTimeImmutable $nowDt): void {
		$config        = ($schema->getConfiguration() ?? []);
		$notifications = ($config['x-openregister-notifications'] ?? null);
		if (is_array($notifications) === false || count($notifications) === 0) {
			return;
		}

		foreach ($notifications as $name => $spec) {
			$trigger = $this->scheduledTrigger(spec: $spec);
			if ($trigger !== null) {
				$this->fireIfDue(fire: new ScheduledFire(schema: $schema, name: (string)$name, trigger: $trigger, nowDt: $nowDt));
			}
		}
	}//end processSchema()

	/**
	 * The trigger of a rule when it is scheduled at an interval of a minute or more, else null.
	 *
	 * @param mixed $spec Notification rule as declared on the schema
	 *
	 * @return array<string, mixed>|null
	 */
	private function scheduledTrigger(mixed $spec): ?array {
		if (is_array($spec) === false) {
			return null;
		}

		$trigger = ($spec['trigger'] ?? null);
		if (is_array($trigger) === false || (string)($trigger['type'] ?? '') !== 'scheduled') {
			return null;
		}

		if ((int)($trigger['intervalSec'] ?? 0) < 60) {
			return null;
		}

		return $trigger;
	}//end scheduledTrigger()

	/**
	 * Fire a rule when its interval has elapsed, then record the fire time.
	 *
	 * The fire time is recorded regardless of per-object errors; the
	 * dispatcher already swallows and logs its own failures.
	 *
	 * @param ScheduledFire $fire The rule to fire
	 *
	 * @return void
	 */
	private function fireIfDue(ScheduledFire $fire): void {
		if ($this->isDue(fire: $fire) === false) {
			return;
		}

		$this->fire(fire: $fire);
		$this->markFired(fire: $fire);
	}//end fireIfDue()

	/**
	 * Whether the rule's interval has elapsed since it last fired.
	 *
	 * Without a state cache the rule never fires: firing every 60 seconds is
	 * worse than skipping.
	 *
	 * @param ScheduledFire $fire The rule
	 *
	 * @return bool
	 */
	private function isDue(ScheduledFire $fire): bool {
		if ($this->stateCache === null) {
			return false;
		}

		$last = $this->stateCache->get($this->stateKey(fire: $fire));
		if (is_int($last) === false && is_string($last) === false) {
			return true;
		}

		return ((int)$last + (int)$fire->trigger['intervalSec']) <= $fire->nowDt->getTimestamp();
	}//end isDue()

	/**
	 * Record the fire time, with a 30 day TTL so monthly schedules survive eviction.
	 *
	 * @param ScheduledFire $fire The rule that fired
	 *
	 * @return void
	 */
	private function markFired(ScheduledFire $fire): void {
		if ($this->stateCache === null) {
			return;
		}

		try {
			$this->stateCache->set($this->stateKey(fire: $fire), $fire->nowDt->getTimestamp(), (60 * 60 * 24 * 30));
		} catch (\Throwable $e) {
			// Don't escalate.
		}
	}//end markFired()

	/**
	 * Cache key of a rule's last fire time.
	 *
	 * @param ScheduledFire $fire The rule
	 *
	 * @return string
	 */
	private function stateKey(ScheduledFire $fire): string {
		return sprintf('sched:%d:%s', $fire->schemaId, $fire->name);
	}//end stateKey()

	/**
	 * Dispatch the rule for every object of the schema that matches its filter and changed.
	 *
	 * @param ScheduledFire $fire The rule to fire
	 *
	 * @return void
	 */
	private function fire(ScheduledFire $fire): void {
		$objects = $this->loadObjects(fire: $fire);
		if ($objects === null) {
			return;
		}

		$objects = $this->window(fire: $fire, objects: $objects);
		$fire->watchedFields = $this->resolveWatchedFields(trigger: $fire->trigger);
		foreach ($objects as $object) {
			$this->fireOn(fire: $fire, object: $object);
		}

		$message = '[ScheduledNotificationJob] fired "%s" on schema %d: matched=%d dispatched=%d deduped=%d of %d';
		$this->logger->info(
			sprintf($message, $fire->name, $fire->schema->getId(), $fire->matched, $fire->dispatched, $fire->deduplicated, count($objects))
		);
	}//end fire()

	/**
	 * The objects of the schema; null (with a warning) when they cannot be read.
	 *
	 * @param ScheduledFire $fire The rule being fired
	 *
	 * @return array|null
	 */
	private function loadObjects(ScheduledFire $fire): ?array {
		try {
			return $this->objectMapper->findBySchema($fire->schemaId);
		} catch (\Throwable $e) {
			$message = '[ScheduledNotificationJob] findBySchema(%d, "%s") failed: %s';
			$this->logger->warning(sprintf($message, $fire->schema->getId(), $fire->name, $e->getMessage()));
			return null;
		}
	}//end loadObjects()

	/**
	 * The slice of objects this run handles.
	 *
	 * Bound the in-memory scan so a pathologically large schema cannot OOM or
	 * stall the cron run (OPS-6 / PERF-3). Excess objects are processed via a
	 * ROTATING window keyed by a persisted per-(schema, notification) offset
	 * cursor: each run handles the next MAX_OBJECTS_PER_FIRE slice and advances
	 * the cursor (wrapping at the end), so every object is eventually swept.
	 *
	 * @param ScheduledFire $fire    The rule being fired
	 * @param array         $objects Every object of the schema
	 *
	 * @return array
	 */
	private function window(ScheduledFire $fire, array $objects): array {
		$total = count($objects);
		if ($total <= self::MAX_OBJECTS_PER_FIRE) {
			return $objects;
		}

		$offset  = $this->readOffset(fire: $fire, total: $total);
		$message = '[ScheduledNotificationJob] schema %d / "%s" returned %d objects; '
			.'processing rotating window [%d, %d) this run (PERF-3 SQL filter pushdown pending)';
		$end     = min($offset + self::MAX_OBJECTS_PER_FIRE, $total);
		$this->logger->warning(sprintf($message, $fire->schema->getId(), $fire->name, $total, $offset, $end));

		// Advance the cursor for the next run; wrap once the schema is swept.
		$this->writeOffset(fire: $fire, offset: $end % $total);
		return array_slice($objects, $offset, self::MAX_OBJECTS_PER_FIRE);
	}//end window()

	/**
	 * The stored window offset; 0 when unreadable or out of range.
	 *
	 * @param ScheduledFire $fire  The rule being fired
	 * @param int           $total Number of objects of the schema
	 *
	 * @return int
	 */
	private function readOffset(ScheduledFire $fire, int $total): int {
		try {
			$offset = (int)$this->appConfig->getValueString('openregister', $fire->offsetKey(), '0');
		} catch (\Throwable $e) {
			$offset = 0;
		}

		if ($offset < 0 || $offset >= $total) {
			return 0;
		}

		return $offset;
	}//end readOffset()

	/**
	 * Store the next window offset; a failure only means the window does not advance.
	 *
	 * @param ScheduledFire $fire   The rule being fired
	 * @param int           $offset Offset of the next run
	 *
	 * @return void
	 */
	private function writeOffset(ScheduledFire $fire, int $offset): void {
		try {
			$this->appConfig->setValueString('openregister', $fire->offsetKey(), (string)$offset);
		} catch (\Throwable $e) {
			$message = '[ScheduledNotificationJob] failed to persist rotation offset for schema %d / "%s": %s';
			$this->logger->warning(sprintf($message, $fire->schema->getId(), $fire->name, $e->getMessage()));
		}
	}//end writeOffset()

	/**
	 * Dispatch for one object when it matches the filter and its fingerprint changed.
	 *
	 * @param ScheduledFire $fire   The rule being fired
	 * @param mixed         $object One object of the window
	 *
	 * @return void
	 */
	private function fireOn(ScheduledFire $fire, mixed $object): void {
		if (($object instanceof ObjectEntity) === false) {
			return;
		}

		$objectData = (array)($object->getObject() ?? []);
		if ($this->filterEvaluator->matches(objectData: $objectData, filter: $fire->filter, now: $fire->nowDt) === true) {
			$fire->matched++;
			$this->fireOnMatch(fire: $fire, object: $object);
		}
	}//end fireOn()

	/**
	 * Dispatch for an object that matched the filter, unless it has no uuid.
	 *
	 * @param ScheduledFire $fire   The rule being fired
	 * @param ObjectEntity  $object The matching object
	 *
	 * @return void
	 */
	private function fireOnMatch(ScheduledFire $fire, ObjectEntity $object): void {
		$objectData = (array)($object->getObject() ?? []);
		$fire->objectUuid = (string)$object->getUuid();
		if ($fire->objectUuid === '') {
			return;
		}

		$fire->fingerprint = $this->computeFingerprint(objectData: $objectData, watchedFields: $fire->watchedFields);
		$this->dispatchUnlessUnchanged(fire: $fire, object: $object);
	}//end fireOnMatch()

	/**
	 * Dispatch for the object in hand, or only touch its dedupe row when its fingerprint is unchanged.
	 *
	 * @param ScheduledFire $fire   The rule being fired, with the object's uuid and fingerprint
	 * @param ObjectEntity  $object The object
	 *
	 * @return void
	 */
	private function dispatchUnlessUnchanged(ScheduledFire $fire, ObjectEntity $object): void {
		$existing = $this->dedupeMapper->findOne(schemaId: $fire->schemaId, ruleKey: $fire->name, objectUuid: $fire->objectUuid);
		if ($existing === null || (string)$existing->getFingerprint() !== $fire->fingerprint) {
			$this->dispatch(fire: $fire, object: $object);
			return;
		}

		// Fingerprint unchanged: touch seen_at, skip dispatch.
		try {
			$this->upsertState(fire: $fire, dispatched: false);
		} catch (\Throwable $e) {
			// Non-fatal — sweep will reclaim eventually.
		}

		$fire->deduplicated++;
	}//end dispatchUnlessUnchanged()

	/**
	 * Dispatch the notification for an object and record it in the dedupe state.
	 *
	 * @param ScheduledFire $fire   The rule being fired, with the object's uuid and fingerprint
	 * @param ObjectEntity  $object The object
	 *
	 * @return void
	 */
	private function dispatch(ScheduledFire $fire, ObjectEntity $object): void {
		try {
			$this->dispatcher->dispatch($object, 'scheduled', ['notificationName' => $fire->name]);
			$this->upsertState(fire: $fire, dispatched: true);
			$fire->dispatched++;
		} catch (\Throwable $e) {
			$message = '[ScheduledNotificationJob] dispatch failed for object %s: %s';
			$this->logger->warning(sprintf($message, $fire->objectUuid, $e->getMessage()));
		}
	}//end dispatch()

	/**
	 * Write the dedupe row of the object in hand.
	 *
	 * @param ScheduledFire $fire       The rule being fired, with the object's uuid and fingerprint
	 * @param bool          $dispatched Whether the notification was dispatched now
	 *
	 * @return void
	 */
	private function upsertState(ScheduledFire $fire, bool $dispatched): void {
		$this->dedupeMapper->upsert(
			schemaId: $fire->schemaId,
			ruleKey: $fire->name,
			objectUuid: $fire->objectUuid,
			fingerprint: $fire->fingerprint,
			now: $fire->seenAt,
			dispatched: $dispatched
		);
	}//end upsertState()

	/**
	 * Resolve the ordered list of object fields to fingerprint for dedup.
	 *
	 * Precedence:
	 *  1. Explicit `trigger.dedupeFields` (array of strings) — used verbatim.
	 *  2. Otherwise the set of `field` keys in `trigger.filter` whose value is
	 *     an operator object using a date operator (`withinNext`, `olderThan`)
	 *     — i.e. the values whose change should re-arm the notification.
	 *  3. Otherwise empty — produces a constant fingerprint so a triggered
	 *     rule fires exactly once per object until pruned (fire-once).
	 *
	 * @param array<string, mixed> $trigger Trigger configuration block.
	 *
	 * @return array<int, string> Sorted, distinct field names.
	 */
	private function resolveWatchedFields(array $trigger): array {
		$explicit = $this->sortedUnique(fields: $this->explicitDedupeFields(trigger: $trigger));
		if ($explicit !== []) {
			return $explicit;
		}

		return $this->sortedUnique(fields: $this->windowFilterFields(trigger: $trigger));
	}//end resolveWatchedFields()

	/**
	 * The non-empty string entries of the trigger's `dedupeFields`.
	 *
	 * @param array<string, mixed> $trigger Scheduled trigger
	 *
	 * @return string[]
	 */
	private function explicitDedupeFields(array $trigger): array {
		$explicit = ($trigger['dedupeFields'] ?? null);
		if (is_array($explicit) === false) {
			return [];
		}

		return array_values(array_filter($explicit, static fn ($field): bool => is_string($field) === true && $field !== ''));
	}//end explicitDedupeFields()

	/**
	 * The filter fields that use a time-window operator (`withinNext`, `olderThan`).
	 *
	 * @param array<string, mixed> $trigger Scheduled trigger
	 *
	 * @return string[]
	 */
	private function windowFilterFields(array $trigger): array {
		$fields = [];
		foreach ((array)($trigger['filter'] ?? []) as $field => $spec) {
			if (is_string($field) === false || $field === '' || is_array($spec) === false) {
				continue;
			}

			if (in_array((string)($spec['operator'] ?? ''), ['withinNext', 'olderThan'], true) === true) {
				$fields[] = $field;
			}
		}

		return $fields;
	}//end windowFilterFields()

	/**
	 * The fields, deduplicated and sorted.
	 *
	 * @param string[] $fields Field names
	 *
	 * @return string[]
	 */
	private function sortedUnique(array $fields): array {
		$fields = array_values(array_unique($fields));
		sort($fields);
		return $fields;
	}//end sortedUnique()

	/**
	 * SHA-1 fingerprint of the watched field values on this object.
	 *
	 * Empty watched-field list yields a stable constant fingerprint so a
	 * triggered rule fires exactly once per object until state is pruned.
	 * Missing fields are encoded as `null` so adding a value re-arms the
	 * rule.
	 *
	 * @param array<string, mixed> $objectData Decoded object payload.
	 * @param array<int, string> $watchedFields Sorted field names.
	 *
	 * @return string Hex SHA-1 string.
	 */
	private function computeFingerprint(array $objectData, array $watchedFields): string {
		if ($watchedFields === []) {
			return sha1('constant');
		}

		$payload = [];
		foreach ($watchedFields as $field) {
			$payload[$field] = ($objectData[$field] ?? null);
		}

		$encoded = json_encode($payload, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			// JSON encode failure (resource etc.) — fall back to var_export.
			$encoded = var_export($payload, true);
		}

		return sha1($encoded);
	}//end computeFingerprint()
}//end class
