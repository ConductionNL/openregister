<?php

/**
 * OpenRegister LifecycleActionInterface
 *
 * Public contract implemented by lifecycle action handlers. When a schema's
 * `x-openregister-lifecycle.transitions[*].actions[]` block names an action,
 * `LifecycleActionExecutor` resolves the action to a handler through
 * `LifecycleActionRegistry` and runs it. Built-in handlers (e.g. `set-fields`)
 * ship with OpenRegister; app-specific handlers are registered via DI tag,
 * where the tag equals the declared `action` name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * Apps (and OpenRegister itself) implement this to run a declared lifecycle
 * action on a transition.
 *
 * Mirrors `LifecycleGuardInterface`: guards authorise a transition (read-only),
 * actions run its side effects. A self-mutating action (e.g. stamping
 * `submittedAt`) MUST return the modified object payload; the executor merges
 * the return value back into the object being saved. A pure side-effect action
 * (e.g. materialising a related object, emitting an event) MUST return the
 * payload it received, unchanged.
 *
 * A handler MUST fail loudly (throw) when it cannot perform its declared work —
 * it MUST NOT silently no-op. Silent no-op is the exact defect the executor
 * exists to eliminate (issue #427).
 *
 * This base declares no `execute()`, so a handler written for either
 * published signature loads. Implement {@see ContextLifecycleActionInterface}
 * (one `LifecycleActionContext`) for new handlers; handlers that still take
 * `(array $objectData, array $previousData, array $parameters, string $actionName)`
 * keep working and may say so with {@see LegacyLifecycleActionInterface}.
 * The executor calls either form through {@see LifecycleActionInvoker}.
 */
interface LifecycleActionInterface {
}//end interface
