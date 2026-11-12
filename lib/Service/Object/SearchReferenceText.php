<?php

/**
 * SearchReferenceText: what a register or schema reference says on its face.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Reads a reference without asking a mapper.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchReferenceText {
	/**
	 * The trimmed reference.
	 *
	 * @var string
	 */
	public readonly string $text;

	/**
	 * Constructor.
	 *
	 * @param int|string $reference The reference.
	 */
	public function __construct(int|string $reference) {
		$this->text = trim((string)$reference);
	}//end __construct()

	/**
	 * Whether the reference says nothing.
	 *
	 * @return bool True for a blank.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function isBlank(): bool {
		return $this->text === '';
	}//end isBlank()

	/**
	 * The positive id the reference spells out.
	 *
	 * @return int|null The id, null for anything else.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function positiveId(): ?int {
		if (ctype_digit($this->text) === true && (int)$this->text > 0) {
			return (int)$this->text;
		}

		return null;
	}//end positiveId()
}//end class
