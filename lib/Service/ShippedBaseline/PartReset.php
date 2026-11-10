<?php

/**
 * One part of a schema reset to its shipped baseline: which part, from what, to what.
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
 * A reset part with the value the instance ran and the shipped value it now runs.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class PartReset {

	/**
	 * Constructor.
	 *
	 * @param string $path The part that was reset.
	 * @param mixed  $from What the instance ran before.
	 * @param mixed  $to   What was shipped, and now runs.
	 */
	public function __construct(
		public readonly string $path,
		public readonly mixed $from,
		public readonly mixed $to,
	) {
	}//end __construct()
}//end class
