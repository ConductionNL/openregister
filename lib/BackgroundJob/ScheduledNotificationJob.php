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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\ScheduledFilterEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Scheduled notification background job.
 *
 * @psalm-suppress UnusedClass
 */
final class ScheduledNotificationJob extends TimedJob
{

    /**
     * Distributed cache holding last-fire timestamps per (schema, notification).
     *
     * @var ICache|null
     */
    private ?ICache $stateCache = null;

    /**
     * Wire collaborators and configure the timed-job interval.
     *
     * @param ITimeFactory                     $time             Nextcloud time factory.
     * @param SchemaMapper                     $schemaMapper     Schema lookup mapper.
     * @param MagicMapper                      $objectMapper     Magic object mapper.
     * @param AnnotationNotificationDispatcher $dispatcher       Notification dispatcher.
     * @param LoggerInterface                  $logger           PSR logger.
     * @param ICacheFactory                    $cacheFactory     Distributed cache factory.
     * @param ScheduledFilterEvaluator         $filterEvaluator  Operator-aware filter evaluator.
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
        private readonly ScheduledFilterEvaluator $filterEvaluator
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
     * @spec openspec/changes/retrofit-2026-05-25-bw-jobs-listeners/tasks.md#task-2
     */
    protected function run($argument): void
    {
        $now   = time();
        // One logical "now" per scan pass so every entry sees the same window
        // (Phase 1 — filter operator evaluator).
        $nowDt = (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('UTC'));

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
    }//end run()

    /**
     * Inspect one schema's notification specs and fire those that are due.
     *
     * @param Schema            $schema Schema being inspected.
     * @param int               $now    Current epoch second.
     * @param DateTimeImmutable $nowDt  Logical "now" for relative-date filters in this scan pass.
     *
     * @return void
     */
    private function processSchema(Schema $schema, int $now, DateTimeImmutable $nowDt): void
    {
        $config        = ($schema->getConfiguration() ?? []);
        $notifications = ($config['x-openregister-notifications'] ?? null);
        if (is_array($notifications) === false || count($notifications) === 0) {
            return;
        }

        foreach ($notifications as $name => $spec) {
            if (is_array($spec) === false) {
                continue;
            }

            $trigger = ($spec['trigger'] ?? null);
            if (is_array($trigger) === false || (string) ($trigger['type'] ?? '') !== 'scheduled') {
                continue;
            }

            $intervalSec = (int) ($trigger['intervalSec'] ?? 0);
            if ($intervalSec < 60) {
                continue;
            }

            $due = $this->isDue(
                schemaId: (int) $schema->getId(),
                notificationName: (string) $name,
                intervalSec: $intervalSec,
                now: $now
            );
            if ($due === false) {
                continue;
            }

            $this->fire(schema: $schema, notificationName: (string) $name, trigger: $trigger, nowDt: $nowDt);

            // Mark as fired regardless of per-object errors; the dispatcher
            // already swallows + logs its own failures.
            $this->markFired(schemaId: (int) $schema->getId(), notificationName: (string) $name, now: $now);
        }//end foreach
    }//end processSchema()

    /**
     * Determine whether enough time has elapsed since the last fire.
     *
     * @param int    $schemaId         Schema identifier.
     * @param string $notificationName Notification key in the schema config.
     * @param int    $intervalSec      Configured interval in seconds.
     * @param int    $now              Current epoch second.
     *
     * @return bool True when due, false otherwise (including missing cache).
     */
    private function isDue(int $schemaId, string $notificationName, int $intervalSec, int $now): bool
    {
        if ($this->stateCache === null) {
            // Without state we'd fire every 60s — better to skip than spam.
            return false;
        }

        $key  = $this->stateKey(schemaId: $schemaId, notificationName: $notificationName);
        $last = $this->stateCache->get($key);
        if (is_int($last) === false && is_string($last) === false) {
            return true;
        }

        return ((int) $last + $intervalSec) <= $now;
    }//end isDue()

    /**
     * Persist the timestamp of the most recent fire for a notification.
     *
     * @param int    $schemaId         Schema identifier.
     * @param string $notificationName Notification key in the schema config.
     * @param int    $now              Current epoch second.
     *
     * @return void
     */
    private function markFired(int $schemaId, string $notificationName, int $now): void
    {
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
     * @param int    $schemaId         Schema identifier.
     * @param string $notificationName Notification key in the schema config.
     *
     * @return string Stable cache key for the (schema, notification) pair.
     */
    private function stateKey(int $schemaId, string $notificationName): string
    {
        return sprintf('sched:%d:%s', $schemaId, $notificationName);
    }//end stateKey()

    /**
     * Fetch matching objects for the schema and dispatch the notification.
     *
     * @param Schema               $schema           Schema whose objects to scan.
     * @param string               $notificationName Notification key in the schema config.
     * @param array<string, mixed> $trigger          Trigger configuration including filters.
     * @param DateTimeImmutable    $nowDt            Logical "now" used for relative-date operators.
     *
     * @return void
     */
    private function fire(Schema $schema, string $notificationName, array $trigger, DateTimeImmutable $nowDt): void
    {
        try {
            $filter  = (array) ($trigger['filter'] ?? []);
            $objects = $this->objectMapper->findBySchema((int) $schema->getId());
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

        $matched = 0;
        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $objectData = (array) ($object->getObject() ?? []);
            if ($this->filterEvaluator->matches(objectData: $objectData, filter: $filter, now: $nowDt) === false) {
                continue;
            }

            try {
                $this->dispatcher->dispatch(
                    $object,
                    'scheduled',
                    ['notificationName' => $notificationName]
                );
                $matched++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        '[ScheduledNotificationJob] dispatch failed for object %s: %s',
                        (string) $object->getUuid(),
                        $e->getMessage()
                    )
                );
            }
        }//end foreach

        $this->logger->info(
            sprintf(
                '[ScheduledNotificationJob] fired "%s" on schema %d: %d/%d objects',
                $notificationName,
                $schema->getId(),
                $matched,
                count($objects)
            )
        );
    }//end fire()

}//end class
