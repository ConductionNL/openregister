<?php

/**
 * The three revisions of one part of a shipped descriptor.
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
 * What one part held when shipped, holds live and holds incoming; {@see DivergenceComparator::ABSENT} when it is not there.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class PartRevisions {

	/**
	 * Constructor.
	 *
	 * @param mixed $baseline What the app shipped.
	 * @param mixed $live     What the instance runs.
	 * @param mixed $incoming What the app now ships.
	 */
	public function __construct(
		public readonly mixed $baseline,
		public readonly mixed $live,
		public readonly mixed $incoming,
	) {
	}//end __construct()
}//end class
