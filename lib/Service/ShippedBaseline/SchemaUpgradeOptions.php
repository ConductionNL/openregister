<?php

/**
 * How a schema upgrade is guarded: the administrator's decisions and whether the baseline is recorded.
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
 * The optional settings of a guarded schema upgrade, with the defaults every caller had.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class SchemaUpgradeOptions {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $decisions Paths an administrator decided to take from upstream.
	 * @param bool               $record    False leaves the baseline unrecorded: the caller records
	 *                                      it with recordShippedSchema() once the definition is written.
	 */
	public function __construct(
		public readonly array $decisions = [],
		public readonly bool $record = true,
	) {
	}//end __construct()
}//end class
