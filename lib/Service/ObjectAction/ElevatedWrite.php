<?php

/**
 * A write the person's rights refused, asked of the open elevation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * The schema and register a refused write goes to and its verb.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-elevation-is-declared-scoped-and-attributed
 */
final readonly class ElevatedWrite {

	/**
	 * Describe the write.
	 *
	 * @param string|int|null $schemaId   The schema written to.
	 * @param string|int|null $registerId The register written to, when the write names one.
	 * @param string          $verb       `create`, `update` or `delete`.
	 */
	public function __construct(
		public string|int|null $schemaId,
		public string|int|null $registerId,
		public string $verb,
	) {
	}//end __construct()
}//end class
