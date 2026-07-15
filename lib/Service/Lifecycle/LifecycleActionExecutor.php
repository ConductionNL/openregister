<?php

/**
 * OpenRegister LifecycleActionExecutor
 *
 * Runs the `actions[]` block a schema declares on a lifecycle transition. For a
 * matched transition it iterates the declared actions, evaluates each action's
 * optional `condition`, resolves the action name to a handler through
 * `LifecycleActionRegistry`, and runs it — threading the (possibly-mutated)
 * object payload through the chain. Makes the declarative
 * `x-openregister-lifecycle.transitions[*].actions[]` contract real (issue #427).
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

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Executes a transition's declared actions in order.
 *
 * The executor is deliberately fail-loud: a missing handler (via the registry)
 * and an unparseable `condition` both throw, so a declared-but-dead action can
 * never silently no-op. Handlers may self-mutate the payload (the executor
 * threads the return value forward) or perform pure side effects (they return
 * the payload unchanged).
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class LifecycleActionExecutor
{
    /**
     * Constructor.
     *
     * @param LifecycleActionRegistry $registry Resolves action names to handler instances.
     * @param LoggerInterface         $logger   Logger for execution diagnostics.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function __construct(
        private readonly LifecycleActionRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Run every declared action for a matched transition.
     *
     * @param array<int, mixed>    $actions      The transition's declared `actions[]` list.
     * @param array<string, mixed> $objectData   The object payload after the lifecycle field moved to its target.
     * @param array<string, mixed> $previousData The object payload before the transition.
     * @param string               $transition   The matched transition (action) name — for diagnostics.
     *
     * @return array<string, mixed> The object payload after all self-mutating actions applied.
     *
     * @throws RuntimeException When an action is malformed, its condition is unparseable, or its handler is missing.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function run(array $actions, array $objectData, array $previousData, string $transition): array
    {
        foreach ($actions as $envelope) {
            if (is_array($envelope) === false) {
                throw new RuntimeException(
                    sprintf('Lifecycle transition "%s" declares a malformed action (not an object).', $transition)
                );
            }

            $actionName = (string) ($envelope['action'] ?? '');
            if ($actionName === '') {
                throw new RuntimeException(
                    sprintf('Lifecycle transition "%s" declares an action without an "action" name.', $transition)
                );
            }

            // Optional per-action condition — evaluated against the new
            // (@self) and old (@previous) payloads. When present and false,
            // the action is skipped; when unparseable, the executor throws
            // (fail loud) rather than skipping silently.
            $condition = ($envelope['condition'] ?? null);
            if (is_string($condition) === true && $condition !== '') {
                $holds = $this->evaluateCondition(
                    condition: $condition,
                    objectData: $objectData,
                    previousData: $previousData
                );
                if ($holds === false) {
                    continue;
                }
            }

            $parameters = ($envelope['actionParameters'] ?? []);
            if (is_array($parameters) === false) {
                $parameters = [];
            }

            $handler    = $this->registry->resolve($actionName);
            $objectData = $handler->execute(
                objectData: $objectData,
                previousData: $previousData,
                parameters: $parameters,
                actionName: $actionName
            );

            $this->logger->debug(
                sprintf('[LifecycleActionExecutor] ran action "%s" on transition "%s".', $actionName, $transition)
            );
        }//end foreach

        return $objectData;
    }//end run()

    /**
     * Evaluate a declared action `condition`.
     *
     * Supports the equality forms observed across the fleet's register.d:
     * `@self.<field> == '<literal>'`, `@self.<field> != '<literal>'`,
     * `@previous.<field> == '<literal>'`, `@previous.<field> != '<literal>'`.
     * An unrecognised condition shape throws — a declared action must not be
     * silently skipped because its condition could not be understood.
     *
     * @param string               $condition    The declared condition expression.
     * @param array<string, mixed> $objectData   The new (@self) payload.
     * @param array<string, mixed> $previousData The old (@previous) payload.
     *
     * @return bool True when the condition holds.
     *
     * @throws RuntimeException When the condition syntax is not recognised.
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    private function evaluateCondition(string $condition, array $objectData, array $previousData): bool
    {
        $pattern = '/^\s*@(self|previous)\.([A-Za-z0-9_]+)\s*(==|!=)\s*\'([^\']*)\'\s*$/';
        if (preg_match($pattern, $condition, $matches) !== 1) {
            throw new RuntimeException(
                sprintf('Lifecycle action condition "%s" is not a supported expression.', $condition)
            );
        }

        [, $scope, $field, $operator, $literal] = $matches;

        $source = $objectData;
        if ($scope === 'previous') {
            $source = $previousData;
        }

        $actual = (string) ($source[$field] ?? '');

        if ($operator === '==') {
            return ($actual === $literal);
        }

        return ($actual !== $literal);
    }//end evaluateCondition()
}//end class
