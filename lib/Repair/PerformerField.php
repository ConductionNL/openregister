<?php

/**
 * One stored performer field of one flow step, as the typing repair reads it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * A performer field: the type its name already implies, and where it is.
 */
final class PerformerField {

	/**
	 * Constructor.
	 *
	 * @param string|null $impliedType The type the field name already implies, or null.
	 * @param string      $where       Where it was found, for the report.
	 */
	public function __construct(
		public readonly ?string $impliedType,
		public readonly string $where,
	) {

	}//end __construct()
}//end class
