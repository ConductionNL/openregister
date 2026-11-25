<?php

/**
 * Reads a timestamp column of a raw aggregate row as a moment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use Exception;

/**
 * A grouped read returns raw rows, not entities, so its timestamps arrive as
 * strings in the platform's format; this turns one into a moment.
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */
trait StoredMomentTrait {

	/**
	 * A stored timestamp as a moment, or null when there is none or it does not parse.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return DateTime|null The moment.
	 */
	private function storedMoment(mixed $value): ?DateTime {
		if ($value instanceof DateTime) {
			return $value;
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Exception $e) {
			return null;
		}
	}//end storedMoment()
}//end trait
