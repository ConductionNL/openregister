<?php

/**
 * ExampleSetDateValue: a date or date-time value read as a moment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;

/**
 * Reads a date or date-time value as a moment, the way the example-set task dates and the rule-run window read one.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
 */
final class ExampleSetDateValue {

	/**
	 * A date or date-time value as a moment, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
	 */
	public static function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$moment = date_create($value);
		if ($moment === false) {
			return null;
		}

		return $moment;
	}//end moment()
}//end class
