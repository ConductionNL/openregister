<?php

/**
 * Flow oversight: refuse a step an organisation's halt covers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Oversight;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use Throwable;

/**
 * The per-organisation companion of KillSwitchCheck.
 *
 * The organisation is the run's own (FlowRun::organisation). A run whose
 * organisation cannot be established is refused while a halt matches its
 * node type: running it unattributed could be exactly the work that was halted.
 *
 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
 */
class OrganisationHaltCheck implements IFlowOversightCheck {

	/**
	 * Constructor.
	 *
	 * @param OrganisationHaltService $halts The halts.
	 * @param FlowRunMapper           $runs  To read a run's organisation.
	 */
	public function __construct(
		private readonly OrganisationHaltService $halts,
		private readonly FlowRunMapper $runs,
	) {
	}//end __construct()

	/**
	 * The check's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function getId(): string {
		return 'openregister.organisation-halt';
	}//end getId()

	/**
	 * Refuse the hop when the run's organisation has a halt covering its node type.
	 *
	 * @param array<string, mixed> $context The run context, with nodeType.
	 *
	 * @return string|null The reason, or null to proceed.
	 *
	 * @spec openspec/changes/organisation-capability-halt/specs/flow-engine/spec.md
	 */
	public function veto(array $context): ?string {
		$matching = $this->halts->haltsMatching(nodeType: (string) ($context['nodeType'] ?? ''));
		if ($matching === []) {
			return null;
		}

		$organisation = $this->runOrganisation(context: $context);
		if ($organisation === null) {
			return 'A halt covers this step and the run\'s organisation could not be established, so the step was refused.';
		}

		foreach ($matching as $halt) {
			if ($halt['organisation'] === $organisation) {
				return sprintf(
					'%s is halted for this organisation since %s by %s: %s',
					$halt['app'],
					$halt['engagedAt'],
					$halt['engagedBy'],
					$halt['reason']
				);
			}
		}

		return null;
	}//end veto()

	/**
	 * The run's organisation, or null when it cannot be established.
	 *
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return string|null The organisation uuid.
	 */
	private function runOrganisation(array $context): ?string {
		$runUuid = trim((string) ($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			return null;
		}

		try {
			$organisation = trim((string) ($this->runs->findByUuid($runUuid)->getOrganisation() ?? ''));
		} catch (Throwable $e) {
			return null;
		}

		if ($organisation === '') {
			return null;
		}

		return $organisation;
	}//end runOrganisation()
}//end class
