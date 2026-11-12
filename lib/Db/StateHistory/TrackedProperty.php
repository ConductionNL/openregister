<?php

/**
 * TrackedProperty: the declared lifecycle property of one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\StateHistory
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\StateHistory;

/**
 * The object and the lifecycle property whose state intervals the history keeps.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final class TrackedProperty {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object.
	 * @param string $property   The declared lifecycle property.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $property,
	) {
	}//end __construct()
}//end class
