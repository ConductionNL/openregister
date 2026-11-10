<?php

/**
 * OpenRegister ReferenceText
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Formats\UuidFormat;

/**
 * Reads the parts of a schema or register reference.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class ReferenceText {

	/**
	 * A reference without its query parameters.
	 *
	 * @param string $reference The reference.
	 *
	 * @return string The part before the question mark.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function withoutQuery(string $reference): string {
		if (str_contains($reference, '?') === true) {
			return substr($reference, 0, strpos($reference, '?'));
		}

		return $reference;
	}//end withoutQuery()

	/**
	 * The last part of a path or URL reference; any other reference as it stands.
	 *
	 * @param string $reference The reference.
	 *
	 * @return string The last part.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function lastSegment(string $reference): string {
		if (str_contains($reference, '/') === true) {
			return substr($reference, strrpos($reference, '/') + 1);
		}

		return $reference;
	}//end lastSegment()

	/**
	 * Whether a value is written as a numeric id or a uuid.
	 *
	 * @param string $value The value.
	 *
	 * @return bool True for an id or uuid.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function isIdentifier(string $value): bool {
		return is_numeric($value) === true || UuidFormat::isCanonical(value: $value) === true;
	}//end isIdentifier()
}//end class
