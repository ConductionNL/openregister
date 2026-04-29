<?php

/**
 * OpenRegister LifecycleValidationListener
 *
 * Subscribes to ObjectUpdatingEvent and rejects updates that move the
 * lifecycle field to a value that no declared transition allows from the
 * current value. Uses the existing StoppableEventInterface contract.
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
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\Lifecycle\LifecycleGuardRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Rejects invalid lifecycle transitions before they are written.
 *
 * Reads `x-openregister-lifecycle` from the schema's configuration block
 * (placed there by SchemaMapper at save time). When the lifecycle field
 * value differs between old and new object:
 * 1. Finds a transition whose `to` matches the new value.
 * 2. Verifies the old value is in that transition's `from` list.
 * 3. Resolves and runs the optional `requires` guard.
 *
 * Any failure stops propagation and sets a structured error on the event,
 * which the controller surfaces as HTTP 422 (invalid transition) or 403
 * (guard denial).
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 */
class LifecycleValidationListener implements IEventListener
{

    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly LifecycleGuardRegistry $guardRegistry,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {}//end __construct()

    public function handle(Event $event): void
    {
        if (($event instanceof ObjectUpdatingEvent) === false) {
            return;
        }

        $oldObject = $event->getOldObject();
        if ($oldObject === null) {
            // No prior state — nothing to validate against. Initial state
            // is enforced by LifecycleInitialStateListener.
            return;
        }

        $newObject = $event->getNewObject();
        $schema    = $this->loadSchema($newObject);
        if ($schema === null) {
            return;
        }

        $annotation = $this->getLifecycleAnnotation($schema);
        if ($annotation === null) {
            return;
        }

        $field   = (string) ($annotation['field'] ?? '');
        $oldData = $oldObject->getObject() ?? [];
        $newData = $newObject->getObject() ?? [];

        $oldValue = $oldData[$field] ?? null;
        $newValue = $newData[$field] ?? null;

        if ($oldValue === $newValue) {
            // No lifecycle change — nothing to validate.
            return;
        }

        if (is_string($newValue) === false || $newValue === '') {
            $this->reject($event, [
                'code'      => 'lifecycle-invalid-value',
                'field'     => $field,
                'attempted' => $newValue,
                'message'   => sprintf('Lifecycle field "%s" must be a non-empty string.', $field),
            ]);
            return;
        }

        $transitions = ($annotation['transitions'] ?? []);
        $matched     = $this->findTransitionByTarget($transitions, (string) $oldValue, $newValue);

        if ($matched === null) {
            $this->reject($event, [
                'code'      => 'lifecycle-invalid-transition',
                'field'     => $field,
                'from'      => $oldValue,
                'attempted' => $newValue,
                'message'   => sprintf(
                    'No transition allows moving "%s" from "%s" to "%s".',
                    $field,
                    (string) $oldValue,
                    $newValue
                ),
            ]);
            return;
        }

        [$action, $spec] = $matched;
        $requires = ($spec['requires'] ?? null);
        if (is_string($requires) === true && $requires !== '') {
            $userId = ($this->userSession->getUser()?->getUID() ?? '');
            $guard  = $this->guardRegistry->resolve($requires);
            $result = $guard->check($newData, $action, $userId);
            if ($result->isAllowed() === false) {
                $this->reject($event, [
                    'code'    => 'lifecycle-guard-denied',
                    'field'   => $field,
                    'action'  => $action,
                    'message' => ($result->getMessage() ?? 'Transition denied by guard.'),
                ]);
            }
        }
    }//end handle()

    /**
     * Find the transition (action, spec) whose `to` matches the new value
     * AND whose `from` list contains the old value.
     *
     * @param array<string, mixed> $transitions Transition map from the annotation.
     * @param string               $oldValue    Current lifecycle field value.
     * @param string               $newValue    Attempted lifecycle field value.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function findTransitionByTarget(array $transitions, string $oldValue, string $newValue): ?array
    {
        foreach ($transitions as $action => $spec) {
            if (is_array($spec) === false) {
                continue;
            }
            if (($spec['to'] ?? null) !== $newValue) {
                continue;
            }
            $from = ($spec['from'] ?? []);
            if (is_array($from) === false) {
                continue;
            }
            if (in_array($oldValue, $from, true) === true) {
                return [(string) $action, $spec];
            }
        }
        return null;
    }//end findTransitionByTarget()

    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $schemaRef = $object->getSchema();
        if ($schemaRef === null || $schemaRef === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($schemaRef);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Lifecycle listener could not load schema "%s": %s', (string) $schemaRef, $e->getMessage())
            );
            return null;
        }
    }//end loadSchema()

    /**
     * @return array<string, mixed>|null
     */
    private function getLifecycleAnnotation(Schema $schema): ?array
    {
        $config     = ($schema->getConfiguration() ?? []);
        $annotation = ($config['x-openregister-lifecycle'] ?? null);
        return is_array($annotation) === true ? $annotation : null;
    }//end getLifecycleAnnotation()

    /**
     * @param array<string, mixed> $error
     */
    private function reject(ObjectUpdatingEvent $event, array $error): void
    {
        $event->setErrors($error);
        $event->stopPropagation();
    }//end reject()

}//end class
