<?php

/**
 * OpenRegister LifecycleGuardInterface
 *
 * Public contract apps implement to authorise a lifecycle transition beyond
 * what OpenRegister's per-object ACLs already enforce. Guards are registered
 * via DI tag; the schema's `x-openregister-lifecycle.transitions[*].requires`
 * field names the tag.
 *
 * @category Lifecycle
 * @package  OCA\OpenRegister\Lifecycle
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

namespace OCA\OpenRegister\Lifecycle;

/**
 * Apps implement this interface to authorise a transition.
 *
 * Guards are read-only — they MUST NOT mutate the object. Side effects
 * (notifications, cascades, derived-field maintenance) belong on
 * `ObjectTransitionedEvent` listeners, not in the guard.
 */
interface LifecycleGuardInterface
{
    /**
     * Authorise (or deny) a transition.
     *
     * @param array<string, mixed> $object The loaded object payload at its current state.
     * @param string               $action The transition action being applied.
     * @param string               $userId The uid of the caller.
     *
     * @return GuardResult Allow or deny + optional message.
     */
    public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
