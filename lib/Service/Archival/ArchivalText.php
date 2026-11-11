<?php

/**
 * OpenRegister ArchivalText
 *
 * The one place the archival services turn a stored value into "a non-empty
 * trimmed string, or nothing". Every reader of a retention, TMLO or schema
 * block asks that question, and each used to carry its own private copy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Non-empty trimmed text out of a stored value.
 *
 * The three readers differ only in which stored types count as text, and that
 * difference is deliberate per caller, so each is its own named method rather
 * than a flag.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class ArchivalText {

	/**
	 * A non-empty trimmed string, or null; only a string counts.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The trimmed string, or null when it says nothing.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public static function fromString(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		return self::nonBlank(text: $value);
	}//end fromString()

	/**
	 * A non-empty trimmed string, or null; a string or an integer counts.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The trimmed text, or null when it says nothing.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public static function fromStringOrInt(mixed $value): ?string {
		if (is_int($value) === true) {
			return (string)$value;
		}

		return self::fromString(value: $value);
	}//end fromStringOrInt()

	/**
	 * A non-empty trimmed string, or null; any scalar counts.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The trimmed text, or null when it says nothing.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public static function fromScalar(mixed $value): ?string {
		if (is_scalar($value) === true) {
			return self::nonBlank(text: (string)$value);
		}

		return null;
	}//end fromScalar()

	/**
	 * The trimmed text, or null when nothing is left.
	 *
	 * @param string $text The text.
	 *
	 * @return string|null The trimmed text, or null when blank.
	 */
	private static function nonBlank(string $text): ?string {
		$trimmed = trim($text);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end nonBlank()
}//end class
