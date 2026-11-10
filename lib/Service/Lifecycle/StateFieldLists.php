<?php

/**
 * OpenRegister StateFieldLists
 *
 * The three decided field lists of one object in one lifecycle state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * What is hidden, what is frozen and what must be filled, already decided.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class StateFieldLists {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $hidden   Properties kept out of the rendered object.
	 * @param array<int, string> $readOnly Properties rendered but refused on change.
	 * @param array<int, string> $required Properties that may not be left empty.
	 */
	public function __construct(
		public readonly array $hidden = [],
		public readonly array $readOnly = [],
		public readonly array $required = [],
	) {
	}//end __construct()
}//end class
