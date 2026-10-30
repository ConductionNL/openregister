<?php

/**
 * Refuses a macro binding that names a flow nobody can run.
 *
 * The shape is {@see MacroActionBinding}'s. This answers the three questions
 * about the flow itself, at schema save time rather than in front of the
 * person who clicked the button:
 *
 * - the flow exists,
 * - it is published,
 * - it has a manual trigger.
 *
 * 🔴 SAVE TIME IS THE POINT. All three failures are silent at run time: an
 * action bound to a missing flow appears in the menu, does nothing when
 * clicked, and looks exactly like a flow that ran and changed nothing. The
 * handler has no way to tell those apart and no way to fix either.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowVersion;

/**
 * Validates the flow a macro action binds to.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */
class MacroActionValidator {

	/**
	 * Constructor.
	 *
	 * @param FlowMapper           $flows      Looks the bound flow up by uuid.
	 * @param FlowTriggerDerivation $derivation Reads a flow's trigger nodes.
	 */
	public function __construct(
		private readonly FlowMapper $flows,
		private readonly FlowTriggerDerivation $derivation,
	) {
	}//end __construct()

	/**
	 * Every refusal this configuration's macro bindings earn.
	 *
	 * Shape first, then the flow itself. A binding whose shape is wrong is not
	 * looked up: "flow must name a flow" and "that flow does not exist" about
	 * the same action would be two complaints about one mistake.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return string[] The refusals, empty when every binding is runnable.
	 *
	 * @psalm-return list<string>
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	public function refusals(array $configuration): array {
		$refusals = MacroActionBinding::refusals(configuration: $configuration);
		if ($refusals !== []) {
			return $refusals;
		}

		foreach (MacroActionBinding::parse(configuration: $configuration) as $binding) {
			$refusal = $this->refusalFor(binding: $binding, configuration: $configuration);
			if ($refusal !== null) {
				$refusals[] = $refusal;
			}
		}

		return $refusals;
	}//end refusals()

	/**
	 * The refusal a binding that names a flow by name earns: the same schema
	 * must declare a flow of that name (`x-openregister-flows`) with a manual
	 * trigger. It is checked against the declaration, because the stored flow
	 * is only made from it after this save.
	 *
	 * @param MacroActionBinding   $binding       The binding.
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return string|null The refusal, or null when the declaration fits.
	 *
	 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-can-name-a-flow-its-schema-declares
	 */
	private function declaredRefusalFor(MacroActionBinding $binding, array $configuration): ?string {
		$declared = ($configuration['x-openregister-flows'] ?? []);
		if (is_array($declared) === false) {
			$declared = [];
		}

		foreach ($declared as $flow) {
			if (is_array($flow) === false || trim((string)($flow['name'] ?? '')) !== $binding->flow) {
				continue;
			}

			foreach ((array)($flow['nodes'] ?? []) as $node) {
				if (is_array($node) === true && (string)($node['type'] ?? '') === FlowNextHint::MANUAL_TRIGGER) {
					return null;
				}
			}

			return sprintf(
				'Action "%s": flow "%s" has no manual trigger, so nothing in it starts when the action is invoked.',
				$binding->action,
				$binding->flow
			);
		}

		return null;
	}//end declaredRefusalFor()

	/**
	 * Whether the schema declares a flow by the name the binding gives.
	 *
	 * @param MacroActionBinding   $binding       The binding.
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/specs/declared-actions/spec.md#requirement-a-macro-can-name-a-flow-its-schema-declares
	 */
	private function declaresByName(MacroActionBinding $binding, array $configuration): bool {
		$declared = ($configuration['x-openregister-flows'] ?? []);
		if (is_array($declared) === false) {
			$declared = [];
		}

		foreach ($declared as $flow) {
			if (is_array($flow) === true && trim((string)($flow['name'] ?? '')) === $binding->flow) {
				return true;
			}
		}

		return false;
	}//end declaresByName()

	/**
	 * The refusal one binding earns, or null when it is runnable.
	 *
	 * @param MacroActionBinding   $binding       The binding.
	 * @param array<string, mixed> $configuration The schema configuration, for flows it declares by name.
	 *
	 * @return string|null The refusal.
	 */
	private function refusalFor(MacroActionBinding $binding, array $configuration = []): ?string {
		try {
			$flow = $this->flows->findByUuid($binding->flow);
		} catch (\Throwable) {
			if ($this->declaresByName(binding: $binding, configuration: $configuration) === true) {
				return $this->declaredRefusalFor(binding: $binding, configuration: $configuration);
			}

			return sprintf(
				'Action "%s": flow "%s" does not exist.',
				$binding->action,
				$binding->flow
			);
		}

		if ($this->isPublished(flow: $flow) === false) {
			return sprintf(
				'Action "%s": flow "%s" is not published, so the action would do nothing.',
				$binding->action,
				$binding->flow
			);
		}

		if ($this->hasManualTrigger(flow: $flow) === false) {
			return sprintf(
				'Action "%s": flow "%s" has no manual trigger, so nothing in it starts when the action is invoked.',
				$binding->action,
				$binding->flow
			);
		}

		return null;
	}//end refusalFor()

	/**
	 * Whether a flow is published.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return bool True when it is.
	 */
	private function isPublished(Flow $flow): bool {
		return ((string)$flow->getLifecycleStatus() === FlowVersion::STATUS_PUBLISHED);
	}//end isPublished()

	/**
	 * Whether a flow carries a manual trigger node.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return bool True when it does.
	 */
	private function hasManualTrigger(Flow $flow): bool {
		foreach ($this->derivation->triggerNodesOf(flow: $flow) as $node) {
			if ((string)($node['type'] ?? '') === FlowNextHint::MANUAL_TRIGGER) {
				return true;
			}
		}

		return false;
	}//end hasManualTrigger()
}//end class
