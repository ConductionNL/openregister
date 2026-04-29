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
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * @psalm-suppress UnusedClass
 */
final class ScheduledNotificationJob extends TimedJob
{

    private ?ICache $stateCache = null;

    public function __construct(
        ITimeFactory $time,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $objectMapper,
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        ICacheFactory $cacheFactory
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
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        $now = time();

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
            $this->processSchema(schema: $schema, now: $now);
        }
    }//end run()

    private function processSchema(Schema $schema, int $now): void
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

            if ($this->isDue(schemaId: (int) $schema->getId(), notificationName: (string) $name, intervalSec: $intervalSec, now: $now) === false) {
                continue;
            }

            $this->fire(schema: $schema, notificationName: (string) $name, trigger: $trigger);

            // Mark as fired regardless of per-object errors; the dispatcher
            // already swallows + logs its own failures.
            $this->markFired(schemaId: (int) $schema->getId(), notificationName: (string) $name, now: $now);
        }
    }//end processSchema()

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

    private function stateKey(int $schemaId, string $notificationName): string
    {
        return sprintf('sched:%d:%s', $schemaId, $notificationName);
    }//end stateKey()

    /**
     * Fetch matching objects for the schema and dispatch the notification.
     *
     * @param array<string, mixed> $trigger
     */
    private function fire(Schema $schema, string $notificationName, array $trigger): void
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
            if ($this->matchesFilter($object, $filter) === false) {
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
        }

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

    /**
     * Simple equality match against object data fields.
     * For v1 we only support flat `{ field: value }` filters; richer
     * shapes (operators, nested paths) are a v1.1 extension.
     *
     * @param array<string, mixed> $filter
     */
    private function matchesFilter(ObjectEntity $object, array $filter): bool
    {
        if (count($filter) === 0) {
            return true;
        }
        $data = (array) ($object->getObject() ?? []);
        foreach ($filter as $key => $expected) {
            $actual = ($data[$key] ?? null);
            if ($actual !== $expected) {
                return false;
            }
        }
        return true;
    }//end matchesFilter()

}//end class
