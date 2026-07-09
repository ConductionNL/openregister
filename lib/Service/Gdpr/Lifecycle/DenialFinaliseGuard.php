<?php

/**
 * OpenRegister DenialFinaliseGuard
 *
 * Lifecycle-transition guard the `dsar-case-subsystem` head's `finaliseDenial`
 * transition references via its `requires` binding (ADR-031 §3). The declarative
 * head owns the lifecycle graph; this class authors the single imperative PHP
 * seam the graph reaches for — a precondition check, not business logic smuggled
 * out of the schema.
 *
 * Contract:
 *   - refuse `finaliseDenial` when the case's `regulatorReference` is empty;
 *   - permit `finaliseDenial` only when a `regulatorReference` is present;
 *   - never gate `draftDenial` (drafting a `denialGround` stays possible without
 *     a regulator reference);
 *   - fail closed — anything other than a confirmed-present reference on the
 *     `finaliseDenial` action denies the transition.
 *
 * The guard is read-only (it never mutates the object); the head's declarative
 * lifecycle performs the state change once the guard allows it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Lifecycle
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

namespace OCA\OpenRegister\Service\Gdpr\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Refuses `finaliseDenial` unless a `regulatorReference` is present.
 */
final class DenialFinaliseGuard implements LifecycleGuardInterface
{

    /**
     * The transition action this guard gates.
     *
     * @var string
     */
    public const ACTION_FINALISE_DENIAL = 'finaliseDenial';

    /**
     * The case property that must be present before finalising a denial.
     *
     * @var string
     */
    public const FIELD_REGULATOR_REFERENCE = 'regulatorReference';

    /**
     * Authorise (or deny) a transition.
     *
     * Only `finaliseDenial` is gated. Any other action (including
     * `draftDenial`) is allowed by this guard — it exists solely to enforce the
     * mandatory-regulator-reference precondition at finalise time. The check
     * fails closed: it allows finalise ONLY when it can positively confirm a
     * non-empty `regulatorReference`; every other outcome denies.
     *
     * @param array<string, mixed> $object The loaded case payload at its current state.
     * @param string               $action The transition action being applied.
     * @param string               $userId The uid of the caller.
     *
     * @return GuardResult Allow or deny + optional deny message.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId is part of the interface contract.
     * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult::allow()/deny() are the sanctioned value-object factories.
     *
     * @spec openspec/changes/dsar-case-engine/specs/dsar-denial-guard/spec.md
     */
    public function check(array $object, string $action, string $userId): GuardResult
    {
        // The gate applies ONLY at finalise. draftDenial (and any other
        // action) is never blocked by this guard — drafting a denialGround
        // must remain possible without a regulatorReference.
        if ($action !== self::ACTION_FINALISE_DENIAL) {
            return GuardResult::allow();
        }

        // Fail closed: only a value that is present, a string, and non-empty
        // after trimming counts as a satisfied precondition. A missing key,
        // a null, a non-string, or a whitespace-only value all deny.
        $reference = ($object[self::FIELD_REGULATOR_REFERENCE] ?? null);
        if (is_string($reference) === true && trim($reference) !== '') {
            return GuardResult::allow();
        }

        return GuardResult::deny(
            message: 'A regulatorReference is required before a denial can be finalised.'
        );

    }//end check()
}//end class
