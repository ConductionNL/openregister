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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use RuntimeException;

/**
 * Apply named lifecycle transitions and report which actions are
 * available from the object's current state.
 *
 * Not declared `final`: TransitionControllerTest doubles this class, and
 * the controller injects it by concrete type. If sealing is reintroduced,
 * extract an interface for the controller to depend on first.
 */
class TransitionEngine
{
    /**
     * Constructor.
     *
     * @param ObjectService     $objectService     Object CRUD service used to load + save the entity.
     * @param SchemaMapper      $schemaMapper      Mapper to resolve the entity's schema.
     * @param IEventDispatcher  $eventDispatcher   Dispatcher used to fire ObjectTransitionedEvent.
     * @param IUserSession      $userSession       Current user session, for actor attribution.
     * @param PermissionHandler $permissionHandler RBAC verdict on the object's `update`/`read` actions (F03).
     *
     * @spec openspec/changes/retrofit-2026-05-24-object-lifecycle/tasks.md#task-2
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SchemaMapper $schemaMapper,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IUserSession $userSession,
        private readonly PermissionHandler $permissionHandler
    ) {
    }//end __construct()

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
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Linear resolve→guard→mutate→save flow; splitting would obscure the transition contract.
     *
     * @spec openspec/changes/retrofit-2026-05-24-object-lifecycle/tasks.md#task-2
     */
    public function transition(string $objectId, string $action): ObjectEntity
    {
        $object = $this->objectService->find(id: $objectId);
        if ($object === null) {
            throw new RuntimeException(sprintf('Object "%s" not found.', $objectId));
        }

        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            throw new RuntimeException('Object schema could not be resolved.');
        }

        // Per-object RBAC: a transition mutates the lifecycle field, so
        // the caller MUST hold `update` permission on this specific
        // object. The downstream `saveObject()` does its own RBAC pass,
        // but we gate explicitly here so that (a) a denial surfaces as
        // 403 with a clear message instead of being absorbed by the
        // save path's generic error envelope, and (b) we don't redo the
        // (potentially expensive) lifecycle annotation lookup before
        // discovering the caller had no business calling /transition
        // in the first place.
        $callerId = $this->userSession->getUser()?->getUID();
        $allowed  = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'update',
            userId: $callerId,
            objectOwner: $object->getOwner(),
            _rbac: true,
            object: $object
        );
        if ($allowed === false) {
            throw new NotAuthorizedException(
                message: sprintf(
                    'You do not have permission to transition object "%s".',
                    $objectId
                )
            );
        }

        $annotation = $this->getLifecycleAnnotation(schema: $schema);
        if ($annotation === null) {
            throw new RuntimeException(
                sprintf('Schema "%s" does not declare x-openregister-lifecycle.', (string) $schema->getSlug())
            );
        }

        $field       = (string) ($annotation['field'] ?? ($annotation['property'] ?? ''));
        $transitions = (array) ($annotation['transitions'] ?? []);

        if (isset($transitions[$action]) === false || is_array($transitions[$action]) === false) {
            throw new RuntimeException(
                sprintf('Transition "%s" is not declared on this schema.', $action)
            );
        }

        $spec        = $transitions[$action];
        $targetState = (string) ($spec['to'] ?? '');
        $from        = (array) ($spec['from'] ?? []);

        $data         = $object->getObject() ?? [];
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
        $data[$field] = $targetState;

        // Snapshot the session user at the transition boundary and forward it
        // explicitly to the save path, so the @self.folder check uses the SAME
        // identity that authorised this transition. Note this does NOT rescue
        // the null-session case: with no session user $actingUser is null and
        // the downstream check default-denies — as intended (PR #1431 4th-pass).
        $actingUser = $this->userSession->getUser();

        $saved = $this->objectService->saveObject(
            object: $data,
            register: $object->getRegister(),
            schema: $object->getSchema(),
            uuid: $object->getUuid(),
            currentUser: $actingUser
        );

        $userId = $actingUser?->getUID();

        $this->eventDispatcher->dispatchTyped(
            new ObjectTransitionedEvent(
                object: $saved,
                action: $action,
                from: $currentValue,
                to: $targetState,
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) RBAC check + missing-object guard + annotation-absent
     * guard + per-transition from/requires/description checks each add one branch; none can be removed
     * without losing safety or fidelity.
     * @SuppressWarnings(PHPMD.NPathComplexity)      RBAC check + missing-object guard + annotation-absent
     * guard + per-transition from/requires/description checks each add one branch; none can be removed
     * without losing safety or fidelity.
     *
     * @spec openspec/changes/retrofit-2026-05-24-object-lifecycle/tasks.md#task-2
     */
    public function availableActions(string $objectId): array
    {
        $object = $this->objectService->find(id: $objectId);
        if ($object === null) {
            throw new RuntimeException(sprintf('Object "%s" not found.', $objectId));
        }

        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return [];
        }

        // Only callers with `read` permission on the object can enumerate
        // available actions — the response would otherwise leak the
        // object's current lifecycle state to anyone who could guess the id.
        $callerId = $this->userSession->getUser()?->getUID();
        $allowed  = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: $callerId,
            objectOwner: $object->getOwner(),
            _rbac: true,
            object: $object
        );
        if ($allowed === false) {
            throw new NotAuthorizedException(
                message: sprintf(
                    'You do not have permission to read object "%s".',
                    $objectId
                )
            );
        }

        $annotation = $this->getLifecycleAnnotation(schema: $schema);
        if ($annotation === null) {
            return [];
        }

        $field        = (string) ($annotation['field'] ?? ($annotation['property'] ?? ''));
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

            $requires    = null;
            $description = null;
            if (isset($spec['requires']) === true) {
                $requires = (string) $spec['requires'];
            }

            if (isset($spec['description']) === true) {
                $description = (string) $spec['description'];
            }

            $available[] = [
                'action'      => (string) $action,
                'to'          => (string) ($spec['to'] ?? ''),
                'requires'    => $requires,
                'description' => $description,
            ];
        }//end foreach

        return $available;
    }//end availableActions()

    /**
     * Load the schema referenced by an object, returning null on failure.
     *
     * @param ObjectEntity $object The object whose schema should be resolved.
     *
     * @return Schema|null The resolved schema, or null when missing/unresolvable.
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

    /**
     * Pull the `x-openregister-lifecycle` annotation off a schema.
     *
     * @param Schema $schema The schema to inspect.
     *
     * @return array<string, mixed>|null The decoded annotation, or null when absent.
     */
    private function getLifecycleAnnotation(Schema $schema): ?array
    {
        $config     = ($schema->getConfiguration() ?? []);
        $annotation = ($config['x-openregister-lifecycle'] ?? null);
        if (is_array($annotation) === true) {
            return $annotation;
        }

        return null;
    }//end getLifecycleAnnotation()
}//end class
