<?php

/**
 * The instance-wide flow kill switch.
 *
 * The one oversight check that is genuinely engine-generic: it does not care
 * what a node does, only that flow execution has been halted. An administrator
 * throws it when flows are misbehaving and the alternative is disabling them
 * one at a time.
 *
 * It is checked BEFORE EACH HOP rather than only when a run starts. A flow that
 * suspends on a wait node and resumes an hour later, or one walking a long
 * graph, would otherwise sail past a switch thrown mid-run — which is exactly
 * the case the switch exists for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Oversight
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Oversight;

use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use OCP\IAppConfig;

/**
 * Vetoes every hop while the instance flow kill switch is set.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
 */
class KillSwitchCheck implements IFlowOversightCheck {
	/**
	 * App-config key holding the kill switch.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'flow_kill_switch';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the kill switch.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * This check's id.
	 *
	 * @return string The namespaced id.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function getId(): string {
		return 'openregister.kill-switch';
	}//end getId()

	/**
	 * Refuse the hop when the kill switch is set.
	 *
	 * @param array<string, mixed> $context The run context (unused — the switch
	 *                                      is instance-wide by design; a
	 *                                      per-flow off-switch is `enabled`).
	 *
	 * @return string|null The reason for refusing, or null to allow the hop.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function veto(array $context): ?string {
		$thrown = $this->appConfig->getValueBool('openregister', self::CONFIG_KEY, false);

		if ($thrown === false) {
			return null;
		}

		return 'The instance flow kill switch is set, so no flow step may run.';
	}//end veto()
}//end class
