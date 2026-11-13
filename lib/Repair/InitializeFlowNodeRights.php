<?php

/**
 * Add the rights flow steps declare to Open Register's action matrix.
 *
 * A right a node declares is granted to administrators when the matrix lacks
 * it, so an administrator can see it and grant it to a group. An entry already
 * in the matrix is never changed: it may be an administrator's choice.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Seeds every declared node right as `["admin"]` when absent.
 */
class InitializeFlowNodeRights implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param FlowNodeRightsGuardInterface  $nodeRights The rights the registered steps declare.
	 * @param OpenRegisterActionAuthService $actionAuth The action matrix.
	 */
	public function __construct(
		private readonly FlowNodeRightsGuardInterface $nodeRights,
		private readonly OpenRegisterActionAuthService $actionAuth,
	) {
	}//end __construct()

	/**
	 * Repair-step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function getName(): string {
		return 'Add the rights flow steps declare to the openregister action matrix';
	}//end getName()

	/**
	 * Add each declared node right the matrix lacks, granted to administrators.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function run(IOutput $output): void {
		try {
			$declared = array_fill_keys(array_keys($this->nodeRights->nodeRights()), ['admin']);
			$added    = $this->actionAuth->addMissing($declared);
		} catch (Throwable $e) {
			$output->warning('Could not add the flow node rights: '.$e->getMessage());
			return;
		}

		if ($added === []) {
			$output->info(sprintf('All %d flow node rights are already in the action matrix.', count($declared)));
			return;
		}

		$output->info('Added flow node rights, granted to administrators: '.implode(', ', $added));
	}//end run()
}//end class
