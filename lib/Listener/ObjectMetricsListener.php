<?php

/**
 * OpenRegister ObjectMetricsListener
 *
 * Records an operational metric row for every object create, update, and delete.
 *
 * The canonical production-observability spec requires monotonic CRUD counters that
 * survive PHP process boundaries: "Counters MUST persist across PHP request
 * boundaries using the `openregister_metrics` database table (already used by
 * `MetricsService`)." Until this listener existed, `MetricsService::recordMetric()`
 * had no caller anywhere in `lib/` — the metrics table was created by a migration,
 * read by nothing, and never written to. The capability was implemented, unit
 * tested, and spec'd `done`, yet no operational metric row was ever produced
 * (openregister#393, hydra gate-52 `orphaned-write-capability`).
 *
 * It listens on the object lifecycle events already dispatched by `MagicMapper` —
 * the canonical write path that every Conduction app inherits — so no hot-path
 * service had to be modified to obtain full CRUD coverage. Each metric row carries
 * the `register` and `schema` as metadata, which is what the spec's
 * `{register=…,schema=…}` counter labels are derived from.
 *
 * Recording is best-effort by construction: `recordMetric()` catches and logs its
 * own database failures, and this listener additionally swallows any Throwable, so
 * observability can never abort the object write it is observing.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\MetricsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persist a CRUD metric row for every object create / update / delete.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent>
 *
 * @spec openspec/specs/production-observability/spec.md#crud-operation-counters
 */
class ObjectMetricsListener implements IEventListener
{

    /**
     * Wire collaborators.
     *
     * @param MetricsService  $metricsService Operational metric writer (fail-soft).
     * @param IUserSession    $userSession    Session, to attribute the metric to a user.
     * @param LoggerInterface $logger         PSR logger for warnings.
     *
     * @return void
     *
     * @spec openspec/specs/production-observability/spec.md#crud-operation-counters
     */
    public function __construct(
        private readonly MetricsService $metricsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Record the matching CRUD metric for an inbound object lifecycle event.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/specs/production-observability/spec.md#crud-operation-counters
     */
    public function handle(Event $event): void
    {
        $metricType = $this->metricTypeFor(event: $event);
        if ($metricType === null) {
            return;
        }

        try {
            $object = $this->objectFor(event: $event);
            if ($object === null) {
                return;
            }

            $this->metricsService->recordMetric(
                metricType: $metricType,
                entityType: 'object',
                entityId: $object->getUuid(),
                status: 'success',
                durationMs: null,
                metadata: [
                    'register' => (string) ($object->getRegister() ?? ''),
                    'schema'   => (string) ($object->getSchema() ?? ''),
                ],
                errorMessage: null,
                userId: $this->currentUserId()
            );
        } catch (Throwable $e) {
            // Observability must never break the write it observes.
            $this->logger->warning(
                message: '[ObjectMetricsListener] Failed to record CRUD metric',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'metric_type' => $metricType,
                    'error'       => $e->getMessage(),
                ]
            );
        }//end try
    }//end handle()

    /**
     * Map an inbound event to its metric type constant.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return string|null The metric type, or null when the event is not one we track.
     *
     * @spec openspec/specs/production-observability/spec.md#crud-operation-counters
     */
    private function metricTypeFor(Event $event): ?string
    {
        return match (true) {
            $event instanceof ObjectCreatedEvent => MetricsService::METRIC_OBJECT_CREATED,
            $event instanceof ObjectUpdatedEvent => MetricsService::METRIC_OBJECT_UPDATED,
            $event instanceof ObjectDeletedEvent => MetricsService::METRIC_OBJECT_DELETED,
            default => null,
        };
    }//end metricTypeFor()

    /**
     * Extract the subject object from an inbound event.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return ObjectEntity|null The affected object, or null when the event is not one we track.
     *
     * @spec openspec/specs/production-observability/spec.md#crud-operation-counters
     */
    private function objectFor(Event $event): ?ObjectEntity
    {
        return match (true) {
            $event instanceof ObjectUpdatedEvent => $event->getNewObject(),
            $event instanceof ObjectCreatedEvent, $event instanceof ObjectDeletedEvent => $event->getObject(),
            default => null,
        };
    }//end objectFor()

    /**
     * Resolve the acting user id, when there is a session.
     *
     * @return string|null The acting user id, or null for background / system writes.
     *
     * @spec openspec/specs/production-observability/spec.md#crud-operation-counters
     */
    private function currentUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end currentUserId()
}//end class
