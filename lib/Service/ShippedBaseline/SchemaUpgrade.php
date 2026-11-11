<?php

/**
 * One app upgrade of one schema: the schema, what the instance runs, and what the release ships.
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
 * The schema an upgrade guards, the live definition and the shipped one with its release.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class SchemaUpgrade {

	/**
	 * Constructor.
	 *
	 * @param string               $slug     The schema slug.
	 * @param array<string, mixed> $live     What the instance runs.
	 * @param ShippedDefinition    $incoming What the app now ships, and which release.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly array $live,
		public readonly ShippedDefinition $incoming,
	) {
	}//end __construct()
}//end class
