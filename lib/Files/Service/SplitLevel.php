<?php

/**
 * OpenRegister SplitLevel.
 *
 * One level of recursive text splitting: the separators still to try and the target chunk sizing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * One level of recursive text splitting: the separators still to try and the target chunk sizing.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SplitLevel {
	/**
	 * Constructor.
	 *
	 * @param list<string> $separators Separators still to try, most preferred first.
	 * @param ChunkSizing  $sizing     The target chunk size and overlap.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly array $separators,
		public readonly ChunkSizing $sizing,
	) {
	}//end __construct()

	/**
	 * The level below this one: the same sizing without the first separator.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function deeper(): self {
		return new self(separators: array_slice($this->separators, 1), sizing: $this->sizing);
	}//end deeper()
}//end class
