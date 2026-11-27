<?php

/**
 * The rows of a grouped report section.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

/**
 * Puts every row a section lists in its groups, 0 included, so a report
 * cannot suggest a listed key does not apply because nothing matched it.
 */
final class ReportSectionRows {
	/**
	 * The groups with every listed row present, in the listed order; keys
	 * the data has but the list does not follow after it.
	 *
	 * @param array<int, mixed> $groups The runner's groups (`key`, `value`).
	 * @param mixed             $rows   The section's listed row keys.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function withListedRows(array $groups, mixed $rows): array {
		$byKey = [];
		foreach ($groups as $group) {
			if (is_array($group) === true) {
				$byKey[(string)($group['key'] ?? '')] = $group;
			}
		}

		$listed = [];
		if (is_array($rows) === true) {
			$listed = array_values(array_unique(array_map(static fn (mixed $row): string => (string)$row, $rows)));
		}

		$result = [];
		foreach ($listed as $key) {
			$result[] = ($byKey[$key] ?? ['key' => $key, 'value' => 0]);
			unset($byKey[$key]);
		}

		return array_merge($result, array_values($byKey));
	}//end withListedRows()
}//end class
