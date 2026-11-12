<?php

/**
 * PropNameReading: the field name in a `prop` operand.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Reads the field name out of a `prop` operand.
 */
trait PropNameReading {

	/**
	 * The name a `prop` operand reads: a string, or the first entry of a list.
	 *
	 * @param mixed $args The `prop` operand.
	 *
	 * @return string The name, '' when there is none.
	 */
	private function propName(mixed $args): string {
		if (is_string($args) === true) {
			return $args;
		}

		if (is_array($args) === true) {
			return (string)($args[0] ?? '');
		}

		return '';
	}//end propName()
}//end trait
