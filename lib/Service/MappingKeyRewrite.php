<?php

/**
 * OpenRegister MappingKeyRewrite
 *
 * One substring swap applied to every array key of a mapped document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Which substring of a key is replaced, and by what (ADR-011 Rule 2).
 *
 * A mapping encodes the dots in its input keys before dot-notation lookups and
 * decodes them in its output; {@see self::encodeDots()} and
 * {@see self::decodeDots()} are those two directions.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class MappingKeyRewrite {

	/**
	 * The encoded form of a dot inside a key.
	 *
	 * @var string
	 */
	public const ENCODED_DOT = '&#46;';

	/**
	 * Build the rewrite.
	 *
	 * @param string $search      The substring to replace.
	 * @param string $replacement What it is replaced by.
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function __construct(
		public readonly string $search,
		public readonly string $replacement,
	) {
	}//end __construct()

	/**
	 * Dots in keys become `&#46;`, so dot notation does not split them.
	 *
	 * @return self The rewrite.
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public static function encodeDots(): self {
		return new self(search: '.', replacement: self::ENCODED_DOT);
	}//end encodeDots()

	/**
	 * `&#46;` in keys becomes a dot again.
	 *
	 * @return self The rewrite.
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public static function decodeDots(): self {
		return new self(search: self::ENCODED_DOT, replacement: '.');
	}//end decodeDots()
}//end class
