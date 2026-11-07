<?php

/**
 * ObjectTextPath — where a value sits in an object's data while its text is read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * Value object: the dotted key prefix and the recursion depth of an array in an object's data.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final class ObjectTextPath {
	/**
	 * Constructor.
	 *
	 * @param string $prefix The dotted key path so far ('' at the root).
	 * @param int    $depth  The recursion depth (0 at the root).
	 */
	public function __construct(
		public readonly string $prefix,
		public readonly int $depth,
	) {
	}//end __construct()

	/**
	 * The dotted key of an entry under this prefix.
	 *
	 * @param int|string $key The entry's key.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function key(int|string $key): string {
		if ($this->prefix === '') {
			return (string)$key;
		}

		return "{$this->prefix}.{$key}";
	}//end key()
}//end class
