<?php

/**
 * A request to reset one part of a schema to its shipped baseline.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

/**
 * Which schema, what it runs now, and which part to reset.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class ResetRequest {

	/**
	 * Constructor.
	 *
	 * @param string               $slug The schema slug.
	 * @param array<string, mixed> $live What the instance runs.
	 * @param string               $path The part to reset.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly array $live,
		public readonly string $path,
	) {
	}//end __construct()
}//end class
