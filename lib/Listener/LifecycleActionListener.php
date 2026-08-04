<?php

/**
 * OpenRegister LifecycleActionListener
 *
 * Subscribes to ObjectUpdatingEvent and runs the `actions[]` block a schema
 * declares on the matched lifecycle transition. Because it hooks the save path
 * — which every mutation goes through, including a plain list-form edit of the
 * lifecycle field — the declared actions run REGARDLESS of transition form.
 * This is the fix for the bypass in issue #427: list-form transitions never
 * went through `TransitionEngine`, so declared actions never ran for them
 * (observed on shillinq `LeaseContract`).
 *
 * Mirrors `Listener\ApprovalChainGateListener`'s schema-parse-off-`getConfiguration()`
 * and transition-matching shape, and `Listener\CalculationOnSaveListener`'s
 * mutate-the-payload-before-persistence approach (`$object->setObject()`).
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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutor;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Runs declared lifecycle actions on the save path, for every transition form.
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class LifecycleActionListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SchemaMapper            $schemaMapper Schema lookup mapper.
     * @param LifecycleActionExecutor $executor     Runs the transition's declared actions.
     * @param LoggerInterface         $logger       Logger for diagnostics.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly LifecycleActionExecutor $executor,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Run declared actions for the attempted lifecycle transition.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectUpdatingEvent) === false) {
            return;
        }

        // A prior listener (transition legality via LifecycleValidationListener,
        // or an approval gate) may have rejected or blocked this transition. In
        // that case the transition is not happening, so its actions must not run.
        if ($event->isPropagationStopped() === true) {
            return;
        }

        $oldObject = $event->getOldObject();
        if ($oldObject === null) {
            // No prior state — this is an initial create, not a transition.
            return;
        }

        $newObject = $event->getNewObject();
        $schema    = $this->loadSchema(object: $newObject);
        if ($schema === null) {
            return;
        }

        $config     = ($schema->getConfiguration() ?? []);
        $annotation = ($config['x-openregister-lifecycle'] ?? null);
        if (is_array($annotation) === false) {
            return;
        }

        $field       = (string) ($annotation['field'] ?? ($annotation['property'] ?? ''));
        $transitions = ($annotation['transitions'] ?? []);
        if ($field === '' || is_array($transitions) === false || $transitions === []) {
            return;
        }

        $oldData  = ($oldObject->getObject() ?? []);
        $newData  = ($newObject->getObject() ?? []);
        $oldValue = ($oldData[$field] ?? null);
        $newValue = ($newData[$field] ?? null);

        if ($oldValue === $newValue || is_string($newValue) === false) {
            return;
        }

        $matched = $this->matchTransition(
            transitions: $transitions,
            oldValue: (string) $oldValue,
            newValue: $newValue
        );
        if ($matched === null) {
            // Not a recognised transition — LifecycleValidationListener rejects
            // this on its own; there is nothing for the executor to run.
            return;
        }

        [$action, $spec] = $matched;

        $actions = ($spec['actions'] ?? null);
        if (is_array($actions) === false || $actions === []) {
            return;
        }

        // Fail-loud by design: a missing handler / unparseable condition throws
        // out of the executor and is NOT caught here — the exception propagates
        // to the save path (HookStoppedException / 500), aborting the transition
        // rather than silently dropping the declared action.
        $result = $this->executor->run(
            actions: $actions,
            objectData: $newData,
            previousData: $oldData,
            transition: $action
        );

        if ($result !== $newData) {
            $newObject->setObject($result);
        }

        $this->logger->debug(
            sprintf('[LifecycleActionListener] executed %d action(s) for transition "%s".', count($actions), $action)
        );
    }//end handle()

    /**
     * Find the transition (action, spec) whose `to` matches the new value AND
     * whose `from` list contains the old value. Mirrors
     * `LifecycleValidationListener::findTransitionByTarget()`.
     *
     * @param array<string, mixed> $transitions Transition map from the annotation.
     * @param string               $oldValue    Current lifecycle field value.
     * @param string               $newValue    Attempted lifecycle field value.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function matchTransition(array $transitions, string $oldValue, string $newValue): ?array
    {
        foreach ($transitions as $action => $spec) {
            if (is_array($spec) === false || ($spec['to'] ?? null) !== $newValue) {
                continue;
            }

            $from = ($spec['from'] ?? []);
            if (is_string($from) === true) {
                $from = [$from];
            }

            if (is_array($from) === true && in_array($oldValue, $from, true) === true) {
                return [(string) $action, $spec];
            }
        }

        return null;
    }//end matchTransition()

    /**
     * Load the schema referenced by an object, returning null on failure.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $schemaRef = $object->getSchema();
        if ($schemaRef === null || $schemaRef === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($schemaRef, _multitenancy: false);
        } catch (\Throwable $e) {
            return null;
        }
    }//end loadSchema()
}//end class
