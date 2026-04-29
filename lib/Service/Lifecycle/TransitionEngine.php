<?php

/**
 * OpenRegister TransitionEngine
 *
 * Action-based sugar over the lifecycle annotation. Looks up the
 * transition by action name, mutates the lifecycle field, saves through
 * the standard ObjectService path (so all the existing validation,
 * eventing, and audit machinery runs unchanged), and dispatches the
 * typed ObjectTransitionedEvent.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use RuntimeException;

/**
 * Apply named lifecycle transitions and report which actions are
 * available from the object's current state.
 */
final class TransitionEngine
{

    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SchemaMapper $schemaMapper,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IUserSession $userSession
    ) {}//end __construct()

    /**
     * Apply a named transition to an object.
     *
     * @param string $objectId Object id/uuid/slug.
     * @param string $action   Transition action name.
     *
     * @return ObjectEntity The saved object after the transition.
     *
     * @throws RuntimeException When the object/schema/transition is missing,
     *                          the action is not allowed from the current
     *                          state, or the underlying save is rejected.
     */
    public function transition(string $objectId, string $action): ObjectEntity
    {
        $object = $this->objectService->find(id: $objectId);
        if ($object === null) {
            throw new RuntimeException(sprintf('Object "%s" not found.', $objectId));
        }

        $schema = $this->loadSchema($object);
        if ($schema === null) {
            throw new RuntimeException('Object schema could not be resolved.');
        }

        $annotation = $this->getLifecycleAnnotation($schema);
        if ($annotation === null) {
            throw new RuntimeException(
                sprintf('Schema "%s" does not declare x-openregister-lifecycle.', (string) $schema->getSlug())
            );
        }

        $field       = (string) ($annotation['field'] ?? '');
        $transitions = (array) ($annotation['transitions'] ?? []);

        if (isset($transitions[$action]) === false || is_array($transitions[$action]) === false) {
            throw new RuntimeException(
                sprintf('Transition "%s" is not declared on this schema.', $action)
            );
        }

        $spec = $transitions[$action];
        $to   = (string) ($spec['to'] ?? '');
        $from = (array) ($spec['from'] ?? []);

        $data        = $object->getObject() ?? [];
        $currentValue = (string) ($data[$field] ?? '');

        if (in_array($currentValue, $from, true) === false) {
            throw new RuntimeException(
                sprintf(
                    'Transition "%s" is not allowed from current state "%s".',
                    $action,
                    $currentValue
                )
            );
        }

        // Mutate the lifecycle field. The validator listener will re-check
        // the transition on save; the guard (if any) will run there too.
        $data[$field] = $to;

        $saved = $this->objectService->saveObject(
            object: $data,
            register: $object->getRegister(),
            schema: $object->getSchema(),
            uuid: $object->getUuid()
        );

        $userId = $this->userSession->getUser()?->getUID();

        $this->eventDispatcher->dispatchTyped(
            new ObjectTransitionedEvent(
                object: $saved,
                action: $action,
                from: $currentValue,
                to: $to,
                userId: $userId,
                register: (string) $object->getRegister(),
                schema: (string) $object->getSchema()
            )
        );

        return $saved;
    }//end transition()

    /**
     * List actions whose `from` includes the object's current lifecycle value.
     *
     * @param string $objectId Object id/uuid/slug.
     *
     * @return array<int, array{action: string, to: string, requires: ?string, description: ?string}>
     */
    public function availableActions(string $objectId): array
    {
        $object = $this->objectService->find(id: $objectId);
        if ($object === null) {
            throw new RuntimeException(sprintf('Object "%s" not found.', $objectId));
        }

        $schema = $this->loadSchema($object);
        if ($schema === null) {
            return [];
        }

        $annotation = $this->getLifecycleAnnotation($schema);
        if ($annotation === null) {
            return [];
        }

        $field        = (string) ($annotation['field'] ?? '');
        $transitions  = (array) ($annotation['transitions'] ?? []);
        $data         = $object->getObject() ?? [];
        $currentValue = (string) ($data[$field] ?? '');

        $available = [];
        foreach ($transitions as $action => $spec) {
            if (is_array($spec) === false) {
                continue;
            }
            $from = (array) ($spec['from'] ?? []);
            if (in_array($currentValue, $from, true) === false) {
                continue;
            }
            $available[] = [
                'action'      => (string) $action,
                'to'          => (string) ($spec['to'] ?? ''),
                'requires'    => isset($spec['requires']) === true ? (string) $spec['requires'] : null,
                'description' => isset($spec['description']) === true ? (string) $spec['description'] : null,
            ];
        }

        return $available;
    }//end availableActions()

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

    /**
     * @return array<string, mixed>|null
     */
    private function getLifecycleAnnotation(Schema $schema): ?array
    {
        $config     = ($schema->getConfiguration() ?? []);
        $annotation = ($config['x-openregister-lifecycle'] ?? null);
        return is_array($annotation) === true ? $annotation : null;
    }//end getLifecycleAnnotation()

}//end class
