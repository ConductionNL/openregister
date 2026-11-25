<?php

/**
 * OpenRegister EmailFormat
 *
 * The one e-mail address check of the formats directory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * An e-mail address as PHP's own filter reads it.
 *
 * Not registered with the object validator: object properties keep the
 * `email` format they had. Used where OpenRegister itself checks an address.
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
 */
class EmailFormat implements Format {

	/**
	 * Whether the data is an e-mail address.
	 *
	 * @param mixed $data The data.
	 *
	 * @return bool True for a string that is an e-mail address.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
	 */
	public function validate(mixed $data): bool {
		return is_string($data) === true && self::isValid(value: $data) === true;
	}//end validate()

	/**
	 * Whether a string is an e-mail address.
	 *
	 * @param string $value The string.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
	 */
	public static function isValid(string $value): bool {
		return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
	}//end isValid()
}//end class
