<?php

/**
 * The read-only term-engine diagnostic, as the diagnostic endpoint reaches it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Runs the term engine read-only and returns its working.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */
interface TermDiagnosticInterface {

	/**
	 * Explain what arming this SLA against this anchor would compute.
	 *
	 * @param TermQuery $query The calendar, the anchor moment, the SLA and the optional rungs.
	 *
	 * @return array<string, mixed> The fire moment, the walk, the roll, the zone and the rungs.
	 *
	 * @throws FlowTimerValidationException When the SLA, the roll or the ladder is refused.
	 */
	public function explain(TermQuery $query): array;
}//end interface
