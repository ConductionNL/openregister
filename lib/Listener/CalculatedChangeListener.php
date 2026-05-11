<?php

/**
 * OpenRegister CalculatedChangeListener
 *
 * Subscribes to ObjectUpdatedEvent. For each `calculatedChange`-triggered
 * notification declared on the schema, reads the materialized calculation
 * value from the new and previous object snapshots and fires the notification
 * only when the value crosses a configured boundary (debounced).
 *
 * Trigger shape:
 *   "trigger": "calculatedChange"          (string shorthand) OR
 *   "trigger": { "type": "calculatedChange" }
 *   "field": "coveragePercent"             — the materialised calculation field
 *   "condition": { "lt": 0.85 }            — new-value condition (must hold)
 *   "previously": { "gte": 0.85 }          — old-value condition (must hold)
 *
 * Debounce: only fires when `condition` is satisfied by the NEW value AND
 * `previously` is satisfied by the OLD value (crossing check). Subsequent
 * saves that leave the value below the boundary without first crossing
 * back above it do NOT re-fire.
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
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ICacheFactory;
use OCP\ICache;
use Psr\Log\LoggerInterface;

/**
 * Listener that fires calculatedChange notifications on boundary crossings.
 *
 * @template-implements IEventListener<ObjectUpdatedEvent>
 */
class CalculatedChangeListener implements IEventListener
{

    /**
     * Supported condition operators.
     */
    private const OPERATORS = ['gt', 'gte', 'lt', 'lte', 'eq', 'ne'];

    /**
     * Distributed cache holding the last-seen value satisfaction state
     * per (schema, notification).
     *
     * @var ICache|null
     */
    private ?ICache $stateCache = null;

    /**
     * Wire collaborators.
     *
     * @param SchemaMapper                     $schemaMapper  Schema lookup mapper.
     * @param AnnotationNotificationDispatcher $dispatcher    Notification dispatcher.
     * @param LoggerInterface                  $logger        PSR logger for warnings.
     * @param ICacheFactory                    $cacheFactory  Distributed-cache factory.
     *
     * @return void
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly AnnotationNotificationDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        ICacheFactory $cacheFactory
    ) {
        try {
            $this->stateCache = $cacheFactory->createDistributed('openregister_calculated_change_state');
        } catch (\Throwable $e) {
            $this->stateCache = null;
        }
    }//end __construct()

    /**
     * Handle the object-updated event.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        error_log('[DEBUG-CCL] handle() called, event=' . get_class($event));
        if (($event instanceof ObjectUpdatedEvent) === false) {
            error_log('[DEBUG-CCL] not ObjectUpdatedEvent, returning');
            return;
        }

        $newObject = $event->getNewObject();
        $oldObject = $event->getOldObject();

        $schema = $this->loadSchema(object: $newObject);
        error_log('[DEBUG-CCL] schema=' . ($schema !== null ? 'found (id=' . $schema->getId() . ')' : 'null'));
        if ($schema === null) {
            return;
        }

        $config        = ($schema->getConfiguration() ?? []);
        $notifications = ($config['x-openregister-notifications'] ?? null);
        error_log('[DEBUG-CCL] notifications count=' . (is_array($notifications) ? count($notifications) : 'none'));
        if (is_array($notifications) === false || count($notifications) === 0) {
            return;
        }

        foreach ($notifications as $name => $spec) {
            error_log('[DEBUG-CCL] processing notification: ' . $name . ', is_array=' . (is_array($spec) ? 'yes' : 'no'));
            if (is_array($spec) === false) {
                continue;
            }

            $isCCT = $this->isCalculatedChangeTrigger(spec: $spec);
            error_log('[DEBUG-CCL] isCalculatedChangeTrigger=' . ($isCCT ? 'true' : 'false') . ' trigger=' . var_export($spec['trigger'] ?? null, true));
            if ($isCCT === false) {
                continue;
            }

            try {
                $this->evaluate(
                    schema: $schema,
                    notificationName: (string) $name,
                    spec: $spec,
                    newObject: $newObject,
                    oldObject: $oldObject
                );
            } catch (\Throwable $e) {
                error_log('[DEBUG-CCL] EXCEPTION in evaluate: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                $this->logger->warning(
                    sprintf(
                        '[CalculatedChangeListener] evaluation of "%s" failed: %s',
                        (string) $name,
                        $e->getMessage()
                    )
                );
            }
        }//end foreach

    }//end handle()

    /**
     * Evaluate one notification spec and dispatch on boundary crossings.
     *
     * The notification fires only when:
     *   1. The new value satisfies `condition`.
     *   2. The old value satisfies `previously` (or `previously` is absent).
     *
     * When `previously` is absent, the listener tracks the last-seen
     * condition-satisfaction state in the cache and only fires on rising
     * edges (false → true transitions).
     *
     * @param Schema               $schema           Schema declaring the notification.
     * @param string               $notificationName Notification key in the schema config.
     * @param array<string, mixed> $spec             The full notification spec block.
     * @param ObjectEntity         $newObject        Object state after the save.
     * @param ObjectEntity|null    $oldObject        Object state before the save (may be null for first save).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function evaluate(
        Schema $schema,
        string $notificationName,
        array $spec,
        ObjectEntity $newObject,
        ?ObjectEntity $oldObject
    ): void {
        $field     = (string) ($spec['field'] ?? '');
        $condition = ($spec['condition'] ?? null);
        if ($field === '' || is_array($condition) === false || count($condition) === 0) {
            return;
        }

        $newData = $newObject->getObject() ?? [];
        $newVal  = ($newData[$field] ?? null);

        // New value must satisfy condition.
        if ($this->satisfies(value: $newVal, condSpec: $condition) === false) {
            // Track that condition is no longer satisfied so the next
            // crossing will re-fire.
            $stateKey = $this->stateKey(schemaId: $schema->getId(), name: $notificationName);
            try {
                $this->stateCache?->set($stateKey, 'not-satisfied', (60 * 60 * 24 * 30));
            } catch (\Throwable) {
                // Best effort.
            }

            return;
        }

        $previouslySpec = ($spec['previously'] ?? null);

        if (is_array($previouslySpec) === true && count($previouslySpec) > 0) {
            // Explicit `previously` block: check the old object's field value.
            $oldData = $oldObject !== null ? ($oldObject->getObject() ?? []) : [];
            $oldVal  = ($oldData[$field] ?? null);
            if ($this->satisfies(value: $oldVal, condSpec: $previouslySpec) === false) {
                // Old value didn't satisfy previously — crossing condition not met.
                return;
            }

            // Both conditions hold: fire and update state cache.
            $this->dispatcher->dispatch(
                $newObject,
                'calculatedChange',
                [
                    'notificationName' => $notificationName,
                    'field'            => $field,
                    'newValue'         => $newVal,
                    'oldValue'         => $oldVal,
                    'condition'        => $condition,
                    'previously'       => $previouslySpec,
                ]
            );

            $stateKey = $this->stateKey(schemaId: $schema->getId(), name: $notificationName);
            try {
                $this->stateCache?->set($stateKey, 'satisfied', (60 * 60 * 24 * 30));
            } catch (\Throwable) {
                // Best effort.
            }

            return;
        }//end if

        // No explicit `previously` block: debounce via state cache —
        // only fire on false → true transitions.
        $stateKey = $this->stateKey(schemaId: $schema->getId(), name: $notificationName);
        $oldState = $this->stateCache?->get($stateKey);
        error_log('[DEBUG-CalculatedChange] stateCache=' . ($this->stateCache !== null ? 'set' : 'null') . ' stateKey=' . $stateKey . ' oldState=' . var_export($oldState, true) . ' will_dispatch=' . var_export($oldState !== 'satisfied', true));

        if ($oldState !== 'satisfied') {
            $this->dispatcher->dispatch(
                $newObject,
                'calculatedChange',
                [
                    'notificationName' => $notificationName,
                    'field'            => $field,
                    'newValue'         => $newVal,
                    'condition'        => $condition,
                ]
            );
        }

        try {
            $this->stateCache?->set($stateKey, 'satisfied', (60 * 60 * 24 * 30));
        } catch (\Throwable) {
            // Best effort.
        }

    }//end evaluate()

    /**
     * Check whether `$value` satisfies the condition specification.
     *
     * Condition spec is a single-key map like `{ "lt": 0.85 }`.
     *
     * @param mixed                $value    The value to test (must be numeric).
     * @param array<string, mixed> $condSpec Single-op condition map.
     *
     * @return bool True when the value satisfies the condition.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function satisfies(mixed $value, array $condSpec): bool
    {
        if (is_numeric($value) === false) {
            return false;
        }

        $lhs = (float) $value;

        foreach ($condSpec as $op => $rhs) {
            if (in_array($op, self::OPERATORS, true) === false) {
                continue;
            }

            if (is_numeric($rhs) === false) {
                return false;
            }

            $rhsFloat = (float) $rhs;
            $result   = match ($op) {
                'gt'  => $lhs > $rhsFloat,
                'gte' => $lhs >= $rhsFloat,
                'lt'  => $lhs < $rhsFloat,
                'lte' => $lhs <= $rhsFloat,
                'eq'  => $lhs === $rhsFloat,
                'ne'  => $lhs !== $rhsFloat,
                default => false,
            };

            if ($result === false) {
                return false;
            }
        }

        return true;

    }//end satisfies()

    /**
     * Determine whether a notification spec uses the `calculatedChange` trigger.
     *
     * Accepts both the string shorthand (`"trigger": "calculatedChange"`) and
     * the structured form (`"trigger": { "type": "calculatedChange" }`).
     *
     * @param array<string, mixed> $spec Notification spec block.
     *
     * @return bool True when the trigger type is `calculatedChange`.
     */
    private function isCalculatedChangeTrigger(array $spec): bool
    {
        $trigger = ($spec['trigger'] ?? null);
        if (is_string($trigger) === true) {
            return $trigger === 'calculatedChange';
        }

        if (is_array($trigger) === true) {
            return (string) ($trigger['type'] ?? '') === 'calculatedChange';
        }

        return false;

    }//end isCalculatedChangeTrigger()

    /**
     * Build a cache key for the per-(schema, notification) debounce state.
     *
     * @param int|string $schemaId         Schema database ID.
     * @param string     $notificationName Notification annotation key.
     *
     * @return string Cache key string.
     */
    private function stateKey(int|string $schemaId, string $notificationName): string
    {
        return sprintf('calculatedChange:%s:%s', $schemaId, $notificationName);
    }//end stateKey()

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

        try {
            return $this->schemaMapper->find($schemaRef);
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadSchema()
}//end class
