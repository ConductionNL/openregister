<?php

/**
 * One guarded schema upgrade in progress: the upgrade and how it is guarded.
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
 * The state ShippedConfigurationGuard's upgrade helpers share (ADR-011 Rule 3).
 *
 * Built by ShippedConfigurationGuard::guardUpgrade() and never handed out.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class SchemaUpgradeRun {

	/**
	 * Constructor.
	 *
	 * @param SchemaUpgrade        $upgrade The schema, the live and the shipped definition.
	 * @param SchemaUpgradeOptions $options The administrator's decisions and whether to record.
	 */
	public function __construct(
		public readonly SchemaUpgrade $upgrade,
		public readonly SchemaUpgradeOptions $options,
	) {
	}//end __construct()
}//end class
