<?php

/**
 * One call of a sub-flow step: the sub-flow, how the step is configured, and the parent run's context.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowToken;

/**
 * One call of {@see SubFlowNode}.
 *
 * A run object (ADR-011 Rule 3): built by `execute()` once the sub-flow is
 * resolved, shared by the fan-out and the per-invocation helpers, and never
 * handed outside the node. It holds the parent's token, so a waited-on child
 * merges into the very instance the parent's later steps read.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final class SubFlowRun {

	/**
	 * Name the call.
	 *
	 * @param SubFlowTarget        $target  The resolved sub-flow, its id and the recursion stack.
	 * @param array<string, mixed> $config  The step configuration.
	 * @param array<string, mixed> $context The parent run's context.
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public function __construct(
		public readonly SubFlowTarget $target,
		public readonly array $config,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * Whether the step calls the sub-flow once per item.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public function fansOut(): bool {
		return ($this->config['fanOut'] ?? false) === true;
	}//end fansOut()

	/**
	 * A child run's context with the parent's token seeded into it as plain
	 * values, never as the parent's instance.
	 *
	 * @param array<string, mixed> $context The child run's context so far.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public function withChildToken(array $context): array {
		$context[FlowToken::CONTEXT_KEY] = $this->parentToken()->all();

		return $context;
	}//end withChildToken()

	/**
	 * Merge what a waited-on child gathered into the parent's token; the
	 * child wins on a conflicting key.
	 *
	 * @param FlowRun $child The finished child run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public function absorb(FlowRun $child): void {
		$this->parentToken()->merge((array)(($child->getContext() ?? [])[FlowToken::CONTEXT_KEY] ?? []));
	}//end absorb()

	/**
	 * The parent's token: its own instance when the context carries one, so a
	 * waited-on child's values merge into what the parent's later steps read.
	 *
	 * @return FlowToken
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	private function parentToken(): FlowToken {
		$token = ($this->context[FlowToken::CONTEXT_KEY] ?? null);
		if ($token instanceof FlowToken === true) {
			return $token;
		}

		return FlowToken::fromArray($token);
	}//end parentToken()
}//end class
