<?php

/**
 * OpenRegister AggregationThresholdListener
 *
 * Subscribes to object-write events and re-evaluates threshold-typed
 * notifications declared in `x-openregister-notifications`. When the
 * referenced aggregation value crosses the configured threshold (i.e.
 * transitions from below → above the operator condition), the listener
 * dispatches the notification through the existing dispatcher.
 *
 * Transition tracking lives in the distributed cache so a notification
 * fires once on breach, not on every subsequent write while still over
 * the threshold.
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Listener that evaluates threshold-typed notifications on object writes.
 *
 * @template-implements IEventListener<ObjectCreatedEvent|ObjectUpdatedEvent|ObjectDeletedEvent|ObjectTransitionedEvent>
 */
class AggregationThresholdListener implements IEventListener
{

    private const STATE_ABOVE = 'above';
    private const STATE_BELOW = 'below';

    /**
     * Distributed cache holding the last threshold state per (schema, notification).
     *
     * @var ICache|null
     */
    private ?ICache $stateCache = null;

    /**
     * Wire collaborators and prepare the state cache.
     *
     * @param SchemaMapper                     $schemaMapper      Schema lookup mapper.
     * @param AggregationRunner                $aggregationRunner Runner that computes aggregation values.
     * @param AnnotationNotificationDispatcher $dispatcher        Notification dispatcher.
     * @param LoggerInterface                  $logger            PSR logger for warnings.
     * @param ICacheFactory                    $cacheFactory      Distributed-cache factory.
     *
     * @return void
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly AggregationRunner $aggregationRunner,
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        ICacheFactory $cacheFactory
    ) {
        try {
            $this->stateCache = $cacheFactory->createDistributed('openregister_threshold_state');
        } catch (\Throwable $e) {
            $this->stateCache = null;
        }
    }//end __construct()

    /**
     * Re-evaluate every threshold notification declared on the schema.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        $object = $this->extractObject(event: $event);
        if ($object === null) {
            return;
        }

        try {
            $schema = $this->loadSchema(object: $object);
        } catch (\Throwable $e) {
            return;
        }

        if ($schema === null) {
            return;
        }

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
            if (is_array($trigger) === false || (string) ($trigger['type'] ?? '') !== 'threshold') {
                continue;
            }

            try {
                $this->evaluate(schema: $schema, notificationName: (string) $name, trigger: $trigger, object: $object);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        '[AggregationThresholdListener] evaluation of "%s" failed: %s',
                        (string) $name,
                        $e->getMessage()
                    )
                );
            }
        }//end foreach
    }//end handle()

    /**
     * Evaluate one notification spec and dispatch on rising-edge crossings.
     *
     * @param Schema               $schema           Schema declaring the notification.
     * @param string               $notificationName Notification key in the schema config.
     * @param array<string, mixed> $trigger          Trigger configuration block.
     * @param ObjectEntity         $object           Object that just changed.
     *
     * @return void
     */
    private function evaluate(Schema $schema, string $notificationName, array $trigger, ObjectEntity $object): void
    {
        $aggregationName = (string) ($trigger['aggregation'] ?? '');
        $op        = (string) ($trigger['op'] ?? '');
        $threshold = ($trigger['value'] ?? null);
        if ($aggregationName === '' || $op === '' || $threshold === null) {
            return;
        }

        $registerSlug = (string) $object->getRegister();
        $schemaSlug   = (string) $schema->getSlug();

        $result = $this->aggregationRunner->run($registerSlug, $schemaSlug, $aggregationName);
        $value  = ($result['value'] ?? null);
        if (is_int($value) === false && is_float($value) === false) {
            return;
        }

        $isAbove  = $this->compare(actual: $value, op: $op, expected: $threshold);
        $newState = $isAbove === true ? self::STATE_ABOVE : self::STATE_BELOW;

        $stateKey = sprintf('threshold:%d:%s', $schema->getId(), $notificationName);
        $oldState = $this->stateCache?->get($stateKey);

        if ($newState === self::STATE_ABOVE && $oldState !== self::STATE_ABOVE) {
            $this->dispatcher->dispatch(
                $object,
                'threshold',
                [
                    'notificationName' => $notificationName,
                    'aggregation'      => $aggregationName,
                    'value'            => $value,
                    'threshold'        => $threshold,
                    'op'               => $op,
                ]
            );
        }

        try {
            // 30 day TTL; long enough that a slow-moving threshold series
            // still has continuity across maintenance restarts.
            $this->stateCache?->set($stateKey, $newState, (60 * 60 * 24 * 30));
        } catch (\Throwable $e) {
            // Don't escalate.
        }
    }//end evaluate()

    /**
     * Compare two values with the configured operator.
     *
     * @param int|float $actual   Numeric value computed from the aggregation.
     * @param string    $op       Comparison operator (gt|gte|lt|lte|eq|ne).
     * @param mixed     $expected Threshold value, must be numeric.
     *
     * @return bool True when the comparison is satisfied, false otherwise.
     */
    private function compare($actual, string $op, $expected): bool
    {
        if (is_numeric($expected) === false) {
            return false;
        }

        $rhs = (float) $expected;
        $lhs = (float) $actual;
        return match ($op) {
            'gt'  => $lhs > $rhs,
            'gte' => $lhs >= $rhs,
            'lt'  => $lhs < $rhs,
            'lte' => $lhs <= $rhs,
            'eq'  => $lhs === $rhs,
            'ne'  => $lhs !== $rhs,
            default => false,
        };
    }//end compare()

    /**
     * Resolve the underlying object for any of the supported event types.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return ObjectEntity|null Object instance, or null when not resolvable.
     */
    private function extractObject(Event $event): ?ObjectEntity
    {
        if ($event instanceof ObjectTransitionedEvent) {
            return $event->getObject();
        }

        if (method_exists($event, 'getObject') === true) {
            $obj = $event->getObject();
            if ($obj instanceof ObjectEntity) {
                return $obj;
            }
        }

        if (method_exists($event, 'getNewObject') === true) {
            $obj = $event->getNewObject();
            if ($obj instanceof ObjectEntity) {
                return $obj;
            }
        }

        return null;
    }//end extractObject()

    /**
     * Look up the schema referenced by an object instance.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $schemaRef = (string) $object->getSchema();
        if ($schemaRef === '') {
            return null;
        }

        // SchemaMapper resolves slug/uuid/id.
        try {
            return $this->schemaMapper->find($schemaRef);
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadSchema()
}//end class
