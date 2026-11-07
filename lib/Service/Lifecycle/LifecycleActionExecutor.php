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
class LifecycleActionExecutor implements LifecycleActionExecutorInterface {
	/**
	 * Constructor.
	 *
	 * @param LifecycleActionRegistry $registry Resolves action names to handler instances.
	 * @param LoggerInterface $logger Logger for execution diagnostics.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LifecycleActionRegistry $registry,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run every declared action for a matched transition.
	 *
	 * @param array<int, mixed> $actions The transition's declared `actions[]` list.
	 * @param array<string, mixed> $objectData The object payload after the lifecycle field moved to its target.
	 * @param array<string, mixed> $previousData The object payload before the transition.
	 * @param string $transition The matched transition (action) name — for diagnostics.
	 *
	 * @return array<string, mixed> The object payload after all self-mutating actions applied.
	 *
	 * @throws RuntimeException When an action is malformed, its condition is unparseable, or its handler is missing.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function run(array $actions, array $objectData, array $previousData, string $transition): array {
		$payload = new TransitionPayload(transition: $transition, objectData: $objectData, previousData: $previousData);
		foreach ($actions as $envelope) {
			$payload = $this->runAction(envelope: $envelope, payload: $payload);
		}

		return $payload->objectData;
	}//end run()

	/**
	 * Run one declared action, unless its condition says to skip it.
	 *
	 * @param mixed             $envelope The declared action.
	 * @param TransitionPayload $payload  The payloads so far.
	 *
	 * @return TransitionPayload The payloads after the action.
	 *
	 * @throws RuntimeException When the action is malformed, its condition is unparseable, or its handler is missing.
	 */
	private function runAction(mixed $envelope, TransitionPayload $payload): TransitionPayload {
		$actionName = $this->actionNameOf(envelope: $envelope, transition: $payload->transition);
		if ($this->conditionHolds(envelope: $envelope, payload: $payload) === false) {
			return $payload;
		}

		$objectData = $this->registry->resolve($actionName)->execute(
			objectData: $payload->objectData,
			previousData: $payload->previousData,
			parameters: $this->parametersOf(envelope: $envelope),
			actionName: $actionName
		);

		$this->logger->debug(sprintf('[LifecycleActionExecutor] ran action "%s" on transition "%s".', $actionName, $payload->transition));
		return $payload->withObjectData(objectData: $objectData);
	}//end runAction()

	/**
	 * The action name an envelope declares, refusing a malformed one.
	 *
	 * @param mixed  $envelope   The declared action.
	 * @param string $transition The matched transition, for the refusal.
	 *
	 * @return string The action name.
	 *
	 * @throws RuntimeException When the envelope is not an object or names no action.
	 */
	private function actionNameOf(mixed $envelope, string $transition): string {
		if (is_array($envelope) === false) {
			throw new RuntimeException(
				sprintf('Lifecycle transition "%s" declares a malformed action (not an object).', $transition)
			);
		}

		$actionName = (string)($envelope['action'] ?? '');
		if ($actionName === '') {
			throw new RuntimeException(
				sprintf('Lifecycle transition "%s" declares an action without an "action" name.', $transition)
			);
		}

		return $actionName;
	}//end actionNameOf()

	/**
	 * Whether an action's optional condition lets it run.
	 *
	 * The condition is evaluated against the new (@self) and old (@previous)
	 * payloads. Absent, the action runs; false, it is skipped; unparseable, the
	 * executor throws (fail loud) rather than skipping silently.
	 *
	 * @param array<string, mixed> $envelope The declared action.
	 * @param TransitionPayload    $payload  The payloads so far.
	 *
	 * @return bool True when the action runs.
	 *
	 * @throws RuntimeException When the condition syntax is not recognised.
	 */
	private function conditionHolds(array $envelope, TransitionPayload $payload): bool {
		$condition = ($envelope['condition'] ?? null);
		if (is_string($condition) === false || $condition === '') {
			return true;
		}

		return $this->evaluateCondition(condition: $condition, payload: $payload);
	}//end conditionHolds()

	/**
	 * The action's parameters, or none when they are not an object.
	 *
	 * @param array<string, mixed> $envelope The declared action.
	 *
	 * @return array<string, mixed> The parameters.
	 */
	private function parametersOf(array $envelope): array {
		$parameters = ($envelope['actionParameters'] ?? []);
		if (is_array($parameters) === false) {
			return [];
		}

		return $parameters;
	}//end parametersOf()

	/**
	 * Evaluate a declared action `condition`.
	 *
	 * Supports the equality forms observed across the fleet's register.d:
	 * `@self.<field> == '<literal>'`, `@self.<field> != '<literal>'`,
	 * `@previous.<field> == '<literal>'`, `@previous.<field> != '<literal>'`.
	 * An unrecognised condition shape throws — a declared action must not be
	 * silently skipped because its condition could not be understood.
	 *
	 * @param string            $condition The declared condition expression.
	 * @param TransitionPayload $payload   The new (@self) and old (@previous) payloads.
	 *
	 * @return bool True when the condition holds.
	 *
	 * @throws RuntimeException When the condition syntax is not recognised.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function evaluateCondition(string $condition, TransitionPayload $payload): bool {
		$pattern = '/^\s*@(self|previous)\.([A-Za-z0-9_]+)\s*(==|!=)\s*\'([^\']*)\'\s*$/';
		if (preg_match($pattern, $condition, $matches) !== 1) {
			throw new RuntimeException(
				sprintf('Lifecycle action condition "%s" is not a supported expression.', $condition)
			);
		}

		[, $scope, $field, $operator, $literal] = $matches;

		$actual = (string)($payload->scope(scope: $scope)[$field] ?? '');
		return (($actual === $literal) === ($operator === '=='));
	}//end evaluateCondition()
}//end class
