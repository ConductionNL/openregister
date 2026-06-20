<?php

/**
 * Notification-dedupe annotation-sync listener.
 *
 * When a schema is created or updated, drop the per-object dedup-state rows
 * keyed under rule names that are no longer present in
 * `x-openregister-notifications`. This prevents a renamed-or-removed
 * scheduled rule from leaving orphan dedup rows that would still consume
 * disk and could re-fire if a UUID is reused.
 *
 * Part of notification-engine-scheduled-conditions Phase 3.4.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Drops orphan dedup-state rows for removed/renamed scheduled rules.
 *
 * @template-implements IEventListener<SchemaCreatedEvent|SchemaUpdatedEvent>
 */
final class NotificationDedupeAnnotationSyncListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param NotificationDedupeStateMapper $mapper Per-object dedup state mapper.
     * @param LoggerInterface               $logger PSR logger.
     */
    public function __construct(
        private readonly NotificationDedupeStateMapper $mapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle the event.
     *
     * @param Event $event Dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        $schema = $this->resolveSchema(event: $event);
        if ($schema === null) {
            return;
        }

        $schemaId = (int) $schema->getId();
        if ($schemaId === 0) {
            return;
        }

        try {
            $config        = ((array) ($schema->getConfiguration() ?? []));
            $notifications = ($config['x-openregister-notifications'] ?? []);
            $current       = [];
            if (is_array($notifications) === true) {
                foreach (array_keys($notifications) as $key) {
                    $current[] = (string) $key;
                }
            }

            $tracked = $this->mapper->listRuleKeysForSchema(schemaId: $schemaId);
            $orphan  = array_diff($tracked, $current);
            foreach ($orphan as $key) {
                $this->mapper->deleteByRule(schemaId: $schemaId, ruleKey: (string) $key);
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                sprintf('[NotificationDedupeAnnotationSyncListener] sync skipped: %s', $e->getMessage())
            );
        }
    }//end handle()

    /**
     * Extract the Schema entity from a create/update event.
     *
     * @param Event $event Event instance.
     *
     * @return Schema|null Schema when the event exposes one, null otherwise.
     */
    private function resolveSchema(Event $event): ?Schema
    {
        if ($event instanceof SchemaCreatedEvent) {
            $schema = $event->getSchema();
            if ($schema instanceof Schema) {
                return $schema;
            }

            return null;
        }

        if ($event instanceof SchemaUpdatedEvent) {
            $schema = $event->getNewSchema();
            if ($schema instanceof Schema) {
                return $schema;
            }

            return null;
        }

        return null;
    }//end resolveSchema()
}//end class
