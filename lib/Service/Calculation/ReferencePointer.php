<?php

/**
 * OpenRegister ReferencePointer
 *
 * A field of a referring schema and the uuid it points at.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Selects the referring objects of a `relatedObject` reference: those whose
 * pointing field holds the updated object's uuid.
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */
final class ReferencePointer {

	/**
	 * Constructor.
	 *
	 * @param string $field The pointing field of the referring schema.
	 * @param string $uuid  The uuid it points at: the updated object's.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function __construct(
		public readonly string $field,
		public readonly string $uuid,
	) {
	}//end __construct()

	/**
	 * The row filter that finds the referring objects.
	 *
	 * @return array<string, string> The pointing field => the uuid.
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function filters(): array {
		return [$this->field => $this->uuid];
	}//end filters()
}//end class
