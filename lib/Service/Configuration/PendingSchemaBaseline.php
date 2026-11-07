<?php

/**
 * A schema definition to write, and the baseline to record once it is written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * What the shipped-baseline guard resolved for one schema.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class PendingSchemaBaseline
{

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $data     The definition to write.
	 * @param array<string, mixed>|null $baseline The baseline to record after the write, null for none.
	 * @param string                    $slug     The schema slug.
	 */
	public function __construct(
		public readonly array $data,
		public readonly ?array $baseline,
		public readonly string $slug,
	) {
	}//end __construct()
}//end class
