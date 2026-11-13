<?php

/**
 * A field one related-row block names, with the block's path for messages.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
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

namespace OCA\OpenRegister\Service\Query;

/**
 * The field a condition is written against, inside the block at `path`.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowBlockField {

	/**
	 * Bundle the field.
	 *
	 * @param string $name The field name, already trimmed and non-empty.
	 * @param string $path The block's path, for messages.
	 */
	public function __construct(
		public string $name,
		public string $path,
	) {
	}//end __construct()

	/**
	 * Where the condition is written, as a caller would spell it: `path[name]`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function label(): string {
		return sprintf('%s[%s]', $this->path, $this->name);
	}//end label()
}//end class
