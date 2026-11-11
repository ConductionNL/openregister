<?php

/**
 * OpenRegister LinkedEntityIds
 *
 * The ids one Nc* typed property contributes to its metadata column.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * An Nc* type and the reference ids extracted for it, in property order.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final readonly class LinkedEntityIds {

	/**
	 * Constructor.
	 *
	 * @param string $ncType The Nc* type name, e.g. NcMail.
	 * @param array<int, string> $ids The extracted ids, never empty.
	 */
	public function __construct(
		public string $ncType,
		public array $ids,
	) {
	}//end __construct()
}//end class
