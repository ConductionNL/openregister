<?php

/**
 * ClearanceResolver: a principal's clearance on one classification scale.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use Psr\Log\LoggerInterface;

/**
 * Resolves the level a principal is cleared to.
 *
 * The highest level among the groups they hold wins. A principal no mapping
 * names takes the declared default, and the lowest level when none is
 * declared. An administrator takes the top level. The default is the
 * minimally cleared corner: an unmapped principal is never widened by accident.
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-principals-clearance-comes-from-declared-group-mappings
 */
final class ClearanceResolver {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the warning for an unresolvable principal.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The clearance of a principal.
	 *
	 * @param ClassificationScale $scale     The schema's scale.
	 * @param string|null         $principal The user id, null when anonymous.
	 * @param array<int, string>  $groups    The groups the principal holds.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-principals-clearance-comes-from-declared-group-mappings
	 */
	public function resolve(ClassificationScale $scale, ?string $principal, array $groups): string {
		$best = null;
		foreach ($scale->clearances() as $entry) {
			$level = ($entry['level'] ?? null);
			if (in_array(($entry['group'] ?? null), $groups, true) === false || $scale->isKnown(label: $level) === false) {
				continue;
			}

			if ($best === null || $scale->rankOf(label: $level) > $scale->rankOf(label: $best)) {
				$best = $level;
			}
		}

		if ($best !== null) {
			return $best;
		}

		$default = $scale->defaultClearance();
		if ($scale->clearances() !== []) {
			$this->logger->warning(
				'Classification clearance for "' . ($principal ?? 'anonymous') . '" could not be resolved from any group; using default "' . $default . '"'
			);
		}

		return $default;
	}//end resolve()

	/**
	 * The clearance of an administrator: the top level, the existing admin bypass.
	 *
	 * @param ClassificationScale $scale The schema's scale.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-a-principals-clearance-comes-from-declared-group-mappings
	 */
	public function forAdministrator(ClassificationScale $scale): string {
		return $scale->highest();
	}//end forAdministrator()
}//end class
