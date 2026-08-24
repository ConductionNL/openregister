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

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\ScheduledFilterEvaluator;
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
	 * Wire collaborators and configure the timed-job interval.
	 *
	 * @param ITimeFactory $time Nextcloud time factory.
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param MagicMapper $objectMapper Magic object mapper.
	 * @param AnnotationNotificationDispatcher $dispatcher Notification dispatcher.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ICacheFactory $cacheFactory Distributed cache factory.
	 * @param ScheduledFilterEvaluator $filterEvaluator Operator-aware filter evaluator.
	 * @param NotificationDedupeStateMapper $dedupeMapper Per-object dedup state mapper.
	 * @param IAppConfig $appConfig App config for tunable retention window.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $objectMapper,
		private readonly AnnotationNotificationDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
		ICacheFactory $cacheFactory,
		private readonly ScheduledFilterEvaluator $filterEvaluator,
		private readonly NotificationDedupeStateMapper $dedupeMapper,
		private readonly IAppConfig $appConfig,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 60);

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
		$now = time();
		// One logical "now" per scan pass so every entry sees the same window
		// (Phase 1 — filter operator evaluator).
		$nowDt = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('UTC'));

		try {
			$schemas = $this->schemaMapper->findAll();
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[ScheduledNotificationJob] schema list failed: %s', $e->getMessage())
			);
			return;
		}

		foreach ($schemas as $schema) {
			if (($schema instanceof Schema) === false) {
				continue;
			}

			$this->processSchema(schema: $schema, now: $now, nowDt: $nowDt);
		}

		// Retention sweep — once per scan pass, best-effort. Drop dedup rows
		// last seen before the configured cutoff so that an object that no
		// longer matches any rule (purged / archived / annotation removed)
		// does not pile up state forever.
		$this->runRetentionSweep(nowDt: $nowDt);
	}//end run()

	/**
	 * Drop dedup rows whose `seen_at` is older than the configured retention.
	 *
	 * @param DateTimeImmutable $nowDt Logical "now" for this scan pass.
	 *
	 * @return void
	 */
	private function runRetentionSweep(DateTimeImmutable $nowDt): void {
		try {
			$days = (int)$this->appConfig->getValueString(
				'openregister',
				'notification_dedupe_retention_days',
				(string)NotificationDedupeStateMapper::DEFAULT_RETENTION_DAYS
			);
		} catch (\Throwable $e) {
			$days = NotificationDedupeStateMapper::DEFAULT_RETENTION_DAYS;
		}

		if ($days <= 0) {
			return;
		}

		$cutoff = DateTime::createFromImmutable($nowDt);
		$cutoff->modify(sprintf('-%d days', $days));

		$this->dedupeMapper->deleteSeenBefore(cutoff: $cutoff);
	}//end runRetentionSweep()

	/**
	 * Inspect one schema's notification specs and fire those that are due.
	 *
	 * @param Schema $schema Schema being inspected.
	 * @param int $now Current epoch second.
	 * @param DateTimeImmutable $nowDt Logical "now" for relative-date filters in this scan pass.
	 *
	 * @return void
	 */
	private function processSchema(Schema $schema, int $now, DateTimeImmutable $nowDt): void {
		$config = ($schema->getConfiguration() ?? []);
		$notifications = ($config['x-openregister-notifications'] ?? null);
		if (is_array($notifications) === false || count($notifications) === 0) {
			return;
		}

		foreach ($notifications as $name => $spec) {
			if (is_array($spec) === false) {
				continue;
			}

			$trigger = ($spec['trigger'] ?? null);
			if (is_array($trigger) === false || (string)($trigger['type'] ?? '') !== 'scheduled') {
				continue;
			}

			$intervalSec = (int)($trigger['intervalSec'] ?? 0);
			if ($intervalSec < 60) {
				continue;
			}

			$due = $this->isDue(
				schemaId: (int)$schema->getId(),
				notificationName: (string)$name,
				intervalSec: $intervalSec,
				now: $now
			);
			if ($due === false) {
				continue;
			}

			$this->fire(schema: $schema, notificationName: (string)$name, trigger: $trigger, nowDt: $nowDt);

			// Mark as fired regardless of per-object errors; the dispatcher
			// already swallows + logs its own failures.
			$this->markFired(schemaId: (int)$schema->getId(), notificationName: (string)$name, now: $now);
		}//end foreach
	}//end processSchema()

	/**
	 * Determine whether enough time has elapsed since the last fire.
	 *
	 * @param int $schemaId Schema identifier.
	 * @param string $notificationName Notification key in the schema config.
	 * @param int $intervalSec Configured interval in seconds.
	 * @param int $now Current epoch second.
	 *
	 * @return bool True when due, false otherwise (including missing cache).
	 */
	private function isDue(int $schemaId, string $notificationName, int $intervalSec, int $now): bool {
		if ($this->stateCache === null) {
			// Without state we'd fire every 60s — better to skip than spam.
			return false;
		}

		$key = $this->stateKey(schemaId: $schemaId, notificationName: $notificationName);
		$last = $this->stateCache->get($key);
		if (is_int($last) === false && is_string($last) === false) {
			return true;
		}

		return ((int)$last + $intervalSec) <= $now;
	}//end isDue()

	/**
	 * Persist the timestamp of the most recent fire for a notification.
	 *
	 * @param int $schemaId Schema identifier.
	 * @param string $notificationName Notification key in the schema config.
	 * @param int $now Current epoch second.
	 *
	 * @return void
	 */
	private function markFired(int $schemaId, string $notificationName, int $now): void {
		if ($this->stateCache === null) {
			return;
		}

		try {
			// 30 day TTL — long enough that even monthly schedules persist
			// through the worst-case eviction cycle.
			$this->stateCache->set(
				$this->stateKey(schemaId: $schemaId, notificationName: $notificationName),
				$now,
				(60 * 60 * 24 * 30)
			);
		} catch (\Throwable $e) {
			// Don't escalate.
		}
	}//end markFired()

	/**
	 * Build the cache key used to track scheduled-notification state.
	 *
	 * @param int $schemaId Schema identifier.
	 * @param string $notificationName Notification key in the schema config.
	 *
	 * @return string Stable cache key for the (schema, notification) pair.
	 */
	private function stateKey(int $schemaId, string $notificationName): string {
		return sprintf('sched:%d:%s', $schemaId, $notificationName);
	}//end stateKey()

	/**
	 * Fetch matching objects for the schema and dispatch the notification.
	 *
	 * @param Schema $schema Schema whose objects to scan.
	 * @param string $notificationName Notification key in the schema config.
	 * @param array<string, mixed> $trigger Trigger configuration including filters.
	 * @param DateTimeImmutable $nowDt Logical "now" used for relative-date operators.
	 *
	 * @return void
	 */
	private function fire(Schema $schema, string $notificationName, array $trigger, DateTimeImmutable $nowDt): void {
		try {
			$filter = (array)($trigger['filter'] ?? []);
			$objects = $this->objectMapper->findBySchema((int)$schema->getId());
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[ScheduledNotificationJob] findBySchema(%d, "%s") failed: %s',
					$schema->getId(),
					$notificationName,
					$e->getMessage()
				)
			);
			return;
		}

		// Bound the in-memory scan so a pathologically large schema cannot OOM or
		// stall the cron run (OPS-6 / PERF-3). Excess objects are processed via a
		// ROTATING window keyed by a persisted per-(schema, notification) offset
		// cursor: each run handles the next MAX_OBJECTS_PER_FIRE slice and advances
		// the cursor (wrapping at the end), so every object is eventually swept —
		// fixing the previous behaviour where array_slice(0, MAX) always processed
		// the same first N and objects beyond N never fired.
		if (count($objects) > self::MAX_OBJECTS_PER_FIRE) {
			$total = count($objects);
			$offsetKey = 'sched_offset:' . ((int)$schema->getId()) . ':' . $notificationName;

			$offset = 0;
			try {
				$offset = (int)$this->appConfig->getValueString('openregister', $offsetKey, '0');
			} catch (\Throwable $e) {
				$offset = 0;
			}

			if ($offset < 0 || $offset >= $total) {
				$offset = 0;
			}

			$this->logger->warning(
				sprintf(
					'[ScheduledNotificationJob] schema %d / "%s" returned %d objects; '
					. 'processing rotating window [%d, %d) this run (PERF-3 SQL filter pushdown pending)',
					$schema->getId(),
					$notificationName,
					$total,
					$offset,
					min($offset + self::MAX_OBJECTS_PER_FIRE, $total)
				)
			);

			$objects = array_slice($objects, $offset, self::MAX_OBJECTS_PER_FIRE);

			// Advance the cursor for the next run; wrap once the schema is swept.
			$nextOffset = ($offset + self::MAX_OBJECTS_PER_FIRE);
			if ($nextOffset >= $total) {
				$nextOffset = 0;
			}

			try {
				$this->appConfig->setValueString('openregister', $offsetKey, (string)$nextOffset);
			} catch (\Throwable $e) {
				// Non-fatal: worst case the window does not advance this run.
				$this->logger->warning(
					sprintf(
						'[ScheduledNotificationJob] failed to persist rotation offset for schema %d / "%s": %s',
						$schema->getId(),
						$notificationName,
						$e->getMessage()
					)
				);
			}
		}//end if

		$watchedFields = $this->resolveWatchedFields(trigger: $trigger);
		$schemaId = (int)$schema->getId();
		$now = DateTime::createFromImmutable($nowDt);

		$matched = 0;
		$dispatched = 0;
		$deduplicated = 0;
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$objectData = (array)($object->getObject() ?? []);
			if ($this->filterEvaluator->matches(objectData: $objectData, filter: $filter, now: $nowDt) === false) {
				continue;
			}

			$matched++;

			$objectUuid = (string)$object->getUuid();
			if ($objectUuid === '') {
				continue;
			}

			$fingerprint = $this->computeFingerprint(objectData: $objectData, watchedFields: $watchedFields);
			$existing = $this->dedupeMapper->findOne(
				schemaId: $schemaId,
				ruleKey: $notificationName,
				objectUuid: $objectUuid
			);

			$shouldDispatch = ($existing === null
				|| (string)$existing->getFingerprint() !== $fingerprint);

			if ($shouldDispatch === false) {
				// Fingerprint unchanged: touch seen_at, skip dispatch.
				try {
					$this->dedupeMapper->upsert(
						schemaId: $schemaId,
						ruleKey: $notificationName,
						objectUuid: $objectUuid,
						fingerprint: $fingerprint,
						now: $now,
						dispatched: false
					);
				} catch (\Throwable $e) {
					// Non-fatal — sweep will reclaim eventually.
				}

				$deduplicated++;
				continue;
			}

			try {
				$this->dispatcher->dispatch(
					$object,
					'scheduled',
					['notificationName' => $notificationName]
				);

				$this->dedupeMapper->upsert(
					schemaId: $schemaId,
					ruleKey: $notificationName,
					objectUuid: $objectUuid,
					fingerprint: $fingerprint,
					now: $now,
					dispatched: true
				);

				$dispatched++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					sprintf(
						'[ScheduledNotificationJob] dispatch failed for object %s: %s',
						$objectUuid,
						$e->getMessage()
					)
				);
			}//end try
		}//end foreach

		$this->logger->info(
			sprintf(
				'[ScheduledNotificationJob] fired "%s" on schema %d: matched=%d dispatched=%d deduped=%d of %d',
				$notificationName,
				$schema->getId(),
				$matched,
				$dispatched,
				$deduplicated,
				count($objects)
			)
		);
	}//end fire()

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
		$explicit = ($trigger['dedupeFields'] ?? null);
		if (is_array($explicit) === true) {
			$fields = [];
			foreach ($explicit as $field) {
				if (is_string($field) === true && $field !== '') {
					$fields[] = $field;
				}
			}

			if ($fields !== []) {
				$fields = array_values(array_unique($fields));
				sort($fields);
				return $fields;
			}
		}

		$filter = (array)($trigger['filter'] ?? []);
		$fields = [];
		foreach ($filter as $field => $spec) {
			if (is_string($field) === false || $field === '') {
				continue;
			}

			if (is_array($spec) === false) {
				continue;
			}

			$operator = (string)($spec['operator'] ?? '');
			if (in_array($operator, ['withinNext', 'olderThan'], true) === true) {
				$fields[] = $field;
			}
		}

		if ($fields === []) {
			return [];
		}

		$fields = array_values(array_unique($fields));
		sort($fields);
		return $fields;
	}//end resolveWatchedFields()

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
