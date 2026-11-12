<?php

/**
 * OpenRegister ObjectSelection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The objects a search or count is restricted to: an id set and the objects one object uses.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class ObjectSelection {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>|null $ids  The ids the search is restricted to, or null for no restriction.
	 * @param string|null             $uses The object whose used objects are searched, or null.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly ?array $ids = null,
		public readonly ?string $uses = null,
	) {
	}//end __construct()
}//end class
