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
 * @spec openspec/changes/notification-delivery-windows/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\Db\QueuedNotification;
use OCA\OpenRegister\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Service\Notification\NotificationDeliveryWindowService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic queue flush for the delivery-window / digest-schedule gate.
 *
 * @psalm-suppress UnusedClass
 */
final class NotificationQueueFlushJob extends TimedJob
{
    /**
     * Wire collaborators and configure the timed-job interval.
     *
     * @param ITimeFactory                      $time            Nextcloud time factory.
     * @param QueuedNotificationMapper          $queuedMapper    Durable queue mapper.
     * @param SchemaMapper                      $schemaMapper    Schema lookup mapper (rule digest config).
     * @param AnnotationNotificationDispatcher  $dispatcher      Notification dispatcher (flush entry point).
     * @param NotificationDeliveryWindowService $windowService   Delivery-window resolver + evaluator.
     * @param DigestScheduleEvaluator           $digestEvaluator Live digest-schedule evaluator.
     * @param LoggerInterface                   $logger          PSR logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly QueuedNotificationMapper $queuedMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly NotificationDeliveryWindowService $windowService,
        private readonly DigestScheduleEvaluator $digestEvaluator,
        private readonly LoggerInterface $logger
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
    protected function run($argument): void
    {
        // `$this->time` (protected on the OCP `Job` base class) rather than
        // `new DateTimeImmutable('now')` so tests can inject a controllable
        // clock — required to exercise the DST-transition re-evaluation
        // scenario deterministically.
        $now = DateTimeImmutable::createFromInterface($this->time->getDateTime());

        try {
            $rows = $this->queuedMapper->findAll();
        } catch (\Throwable $e) {
            $this->logger->warning('[NotificationQueueFlushJob] queue scan failed: '.$e->getMessage());
            return;
        }

        if (count($rows) === 0) {
            return;
        }

        $groups = [];
        foreach ($rows as $row) {
            $key            = $row->getSchemaId().'|'.$row->getRuleKey().'|'.$row->getRecipient();
            $groups[$key][] = $row;
        }

        $flushed = 0;
        foreach ($groups as $groupRows) {
            $flushed += $this->processGroup(rows: $groupRows, now: $now);
        }

        if ($flushed > 0) {
            $this->logger->info(sprintf('[NotificationQueueFlushJob] flushed %d queued notification(s)', $flushed));
        }

    }//end run()

    /**
     * Evaluate and flush one `(schema_id, rule_key, recipient)` group.
     *
     * @param array<int, QueuedNotification> $rows Rows in the group.
     * @param DateTimeImmutable              $now  Logical "now" for this scan pass.
     *
     * @return int Number of rows flushed.
     */
    private function processGroup(array $rows, DateTimeImmutable $now): int
    {
        $first     = $rows[0];
        $recipient = (string) $first->getRecipient();

        // Window-active blocks the WHOLE group, regardless of digest state
        // (window-overlap semantics: delivery waits for the later of
        // quiet-hours-end and digest-due-time).
        $window = $this->windowService->getForUser(userId: $recipient);
        if ($window !== null && $this->windowService->isInsideWindow(window: $window, now: $now) === true) {
            return 0;
        }

        $digest = $this->resolveDigestSpec(schemaId: (int) $first->getSchemaId(), ruleKey: (string) $first->getRuleKey());

        $dueRows = [];
        foreach ($rows as $row) {
            if ($digest === null) {
                // No digest schedule declared (or it was removed from the
                // schema since the row was queued) — the window has
                // cleared, so the row is due immediately.
                $dueRows[] = $row;
                continue;
            }

            $enqueuedAt = DateTimeImmutable::createFromInterface($row->getCreatedAt());
            if ($this->digestEvaluator->isDue(digest: $digest, enqueuedAt: $enqueuedAt, now: $now) === true) {
                $dueRows[] = $row;
            }
        }

        if (count($dueRows) === 0) {
            return 0;
        }

        try {
            $this->dispatcher->dispatchQueued(queuedRows: $dueRows);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    '[NotificationQueueFlushJob] dispatchQueued failed for rule="%s" recipient="%s": %s',
                    $first->getRuleKey(),
                    $recipient,
                    $e->getMessage()
                )
            );
            return 0;
        }

        foreach ($dueRows as $row) {
            $this->queuedMapper->deleteById(id: (int) $row->getId());
        }

        return count($dueRows);

    }//end processGroup()

    /**
     * Resolve the rule's `digest` block from the owning schema, or null
     * when the schema/rule/digest is missing or malformed.
     *
     * @param int    $schemaId Owning schema id.
     * @param string $ruleKey  Notification annotation key.
     *
     * @return array<string, mixed>|null
     */
    private function resolveDigestSpec(int $schemaId, string $ruleKey): ?array
    {
        try {
            $schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
        } catch (\Throwable $e) {
            return null;
        }

        $config        = ($schema->getConfiguration() ?? []);
        $notifications = ($config['x-openregister-notifications'] ?? null);
        if (is_array($notifications) === false) {
            return null;
        }

        $spec = ($notifications[$ruleKey] ?? null);
        if (is_array($spec) === false) {
            return null;
        }

        $digest = ($spec['digest'] ?? null);
        if (is_array($digest) === false || $this->digestEvaluator->isValidDigestSpec($digest) === false) {
            return null;
        }

        return $digest;

    }//end resolveDigestSpec()
}//end class
