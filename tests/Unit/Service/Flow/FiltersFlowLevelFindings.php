<?php

/**
 * Separates per-NODE findings from per-DOCUMENT ones.
 *
 * `FlowNodePreflight::inspect()` reports two kinds of thing in one list. Most
 * findings are about a node — its type is unknown, its config has a key the
 * node will ignore. Two are about the document as a whole: it has no trigger
 * node, or no end node.
 *
 * The fixtures in these tests are FRAGMENTS, built to exercise one config or
 * type question. They are genuinely missing a trigger and an end, so those two
 * findings are true of them and say nothing about the subject under test —
 * asserting over them would make every config test also a completeness test,
 * and a change to either would break both.
 *
 * The completeness rules have their own tests, with their own positive control,
 * in {@see FlowEntryAndExitTest}.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodePreflight;

/**
 * Filters document-level findings out of a preflight report.
 */
trait FiltersFlowLevelFindings {

	/**
	 * A report's warnings, minus the two document-level ones.
	 *
	 * @param array $report The preflight report.
	 *
	 * @return array<int, array<string, mixed>> The per-node warnings.
	 */
	private function nodeWarnings(array $report): array {
		$documentLevel = [
			FlowNodePreflight::REASON_NO_TRIGGER,
			FlowNodePreflight::REASON_NO_END,
		];

		return array_values(
			array_filter(
				($report['warnings'] ?? []),
				static function (array $warning) use ($documentLevel): bool {
					return in_array(($warning['reason'] ?? ''), $documentLevel, true) === false;
				}
			)
		);

	}//end nodeWarnings()
}//end trait
