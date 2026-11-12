<?php

/**
 * NotificationQueueFlushJob — durable notification-queue flush.
 *
 * 60s `TimedJob` that scans every `QueuedNotification` row (quiet-hours
 * suppressed and/or fixed-time digest-scheduled events, held by
 * `AnnotationNotificationDispatcher`'s delivery-window gate) and flushes
 * the ones whose holding condition has cleared.
 *
 * Live re-evaluation, not a precomputed `dueAt`: on every tick this job
 * re-resolves the recipient's CURRENT delivery-window state (wall clock in
 * the window's declared IANA timezone) and the rule's CURRENT digest
 * schedule state, and only flushes a row when today's evaluation says
 * "outside the window" / "digest time reached" — mirroring how
 * `ScheduledNotificationJob` re-evaluates `trigger.filter` live on every
 * tick rather than trusting a stored decision. This is what makes the gate
 * DST-safe (see
 * openspec/changes/notification-delivery-windows/design.md).
 *
 * Rows sharing a `(schema_id, rule_key, recipient)` triple are grouped and
 * flushed together as one summary notification via
 * `AnnotationNotificationDispatcher::dispatchQueued()`. Within a group, a
 * recipient's active quiet-hours window blocks the WHOLE group (window
 * overlap semantics: delivery waits for the later of quiet-hours-end and
 * digest-due-time); once the window has cleared, each row is released
 * individually once its OWN digest schedule (if any) is due — a row
 * queued after the last scheduled occurrence waits for the NEXT one.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\QueueWait;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Periodic queue flush for the delivery-window / digest-schedule gate.
 *
 * @psalm-suppress UnusedClass
 */
final class NotificationQueueFlushJob extends TimedJob {
	/**
	 * Wire collaborators and configure the timed-job interval.
	 *
	 * @param ITimeFactory       $time     Nextcloud time factory.
	 * @param QueueFlushTiming   $timing   Digest config, delivery window and digest schedule.
	 * @param QueueFlushDelivery $delivery The durable queue, the dispatcher and the logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly QueueFlushTiming $timing,
		private readonly QueueFlushDelivery $delivery,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 60);
	}//end __construct()

	/**
	 * Scan every queued row, group by (schema_id, rule_key, recipient),
	 * and flush the groups/rows whose holding condition has cleared.
	 *
	 * @param mixed $argument Background-job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		// `$this->time` (protected on the OCP `Job` base class) rather than
		// `new DateTimeImmutable('now')` so tests can inject a controllable
		// clock — required to exercise the DST-transition re-evaluation
		// scenario deterministically.
		$now = DateTimeImmutable::createFromInterface($this->time->getDateTime());

		try {
			$rows = $this->delivery->queuedMapper->findAll();
		} catch (\Throwable $e) {
			$this->delivery->logger->warning('[NotificationQueueFlushJob] queue scan failed: ' . $e->getMessage());
			return;
		}

		if (count($rows) === 0) {
			return;
		}

		$flushed = 0;
		foreach ($this->groupRows(rows: $rows) as $groupRows) {
			$flushed += $this->processGroup(rows: $groupRows, now: $now);
		}

		if ($flushed > 0) {
			$this->delivery->logger->info(sprintf('[NotificationQueueFlushJob] flushed %d queued notification(s)', $flushed));
		}

	}//end run()

	/**
	 * Group queued rows by `(schema_id, rule_key, recipient)`, keeping scan order.
	 *
	 * @param array<int, QueuedNotification> $rows The queued rows.
	 *
	 * @return array<string, list<QueuedNotification>> The groups.
	 */
	private function groupRows(array $rows): array {
		$groups = [];
		foreach ($rows as $row) {
			$key = $row->getSchemaId() . '|' . $row->getRuleKey() . '|' . $row->getRecipient();
			$groups[$key][] = $row;
		}

		return $groups;
	}//end groupRows()

	/**
	 * Evaluate and flush one `(schema_id, rule_key, recipient)` group.
	 *
	 * @param array<int, QueuedNotification> $rows Rows in the group.
	 * @param DateTimeImmutable $now Logical "now" for this scan pass.
	 *
	 * @return int Number of rows flushed.
	 */
	private function processGroup(array $rows, DateTimeImmutable $now): int {
		$first = $rows[0];

		// Window-active blocks the WHOLE group, regardless of digest state
		// (window-overlap semantics: delivery waits for the later of
		// quiet-hours-end and digest-due-time).
		if ($this->insideWindow(recipient: (string)$first->getRecipient(), now: $now) === true) {
			return 0;
		}

		$digest = $this->resolveDigestSpec(schemaId: (int)$first->getSchemaId(), ruleKey: (string)$first->getRuleKey());

		// No digest schedule declared (or it was removed from the schema since
		// the row was queued): the window has cleared, so every row is due.
		$dueRows = $rows;
		if ($digest !== null) {
			$dueRows = array_values(
				array_filter(
					$rows,
					fn (QueuedNotification $row): bool => $this->timing->digestEvaluator->isDue(
						digest: $digest,
						wait: new QueueWait(enqueuedAt: DateTimeImmutable::createFromInterface($row->getCreatedAt()), now: $now)
					)
				)
			);
		}

		if (count($dueRows) === 0) {
			return 0;
		}

		return $this->flushRows(first: $first, dueRows: $dueRows);
	}//end processGroup()

	/**
	 * Whether the recipient's delivery window holds notifications back right now.
	 *
	 * @param string            $recipient The group's recipient.
	 * @param DateTimeImmutable $now       Logical "now" for this scan pass.
	 *
	 * @return bool True when inside an active window.
	 */
	private function insideWindow(string $recipient, DateTimeImmutable $now): bool {
		$window = $this->timing->windowService->getForUser(userId: $recipient);

		return $window !== null && $this->timing->windowService->isInsideWindow(window: $window, now: $now) === true;
	}//end insideWindow()

	/**
	 * Dispatch the due rows as one batch and delete them once dispatched.
	 *
	 * @param QueuedNotification             $first   The group's first row (rule key and recipient for the log).
	 * @param array<int, QueuedNotification> $dueRows The due rows.
	 *
	 * @return int Number of rows flushed (0 when the dispatch failed).
	 */
	private function flushRows(QueuedNotification $first, array $dueRows): int {
		try {
			$this->delivery->dispatcher->dispatchQueued(queuedRows: $dueRows);
		} catch (\Throwable $e) {
			$this->delivery->logger->warning(
				sprintf(
					'[NotificationQueueFlushJob] dispatchQueued failed for rule="%s" recipient="%s": %s',
					$first->getRuleKey(),
					(string)$first->getRecipient(),
					$e->getMessage()
				)
			);
			return 0;
		}

		foreach ($dueRows as $row) {
			$this->delivery->queuedMapper->deleteById(id: (int)$row->getId());
		}

		return count($dueRows);
	}//end flushRows()

	/**
	 * Resolve the rule's `digest` block from the owning schema, or null
	 * when the schema/rule/digest is missing or malformed.
	 *
	 * @param int $schemaId Owning schema id.
	 * @param string $ruleKey Notification annotation key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveDigestSpec(int $schemaId, string $ruleKey): ?array {
		try {
			$schema = $this->timing->schemaMapper->find($schemaId, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}

		return $this->digestOf(configuration: ($schema->getConfiguration() ?? []), ruleKey: $ruleKey);
	}//end resolveDigestSpec()

	/**
	 * A rule's valid `digest` block from a schema configuration, or null.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 * @param string               $ruleKey       Notification annotation key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function digestOf(array $configuration, string $ruleKey): ?array {
		$spec = $this->ruleSpec(configuration: $configuration, ruleKey: $ruleKey);
		if ($spec === null) {
			return null;
		}

		$digest = ($spec['digest'] ?? null);
		if (is_array($digest) === false || $this->timing->digestEvaluator->isValidDigestSpec($digest) === false) {
			return null;
		}

		return $digest;
	}//end digestOf()

	/**
	 * A rule's notification annotation from a schema configuration, or null.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 * @param string               $ruleKey       Notification annotation key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ruleSpec(array $configuration, string $ruleKey): ?array {
		$notifications = ($configuration['x-openregister-notifications'] ?? null);
		if (is_array($notifications) === false) {
			return null;
		}

		$spec = ($notifications[$ruleKey] ?? null);
		if (is_array($spec) === false) {
			return null;
		}

		return $spec;
	}//end ruleSpec()
}//end class
