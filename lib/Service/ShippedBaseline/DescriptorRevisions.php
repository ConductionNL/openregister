<?php

/**
 * The three revisions of a shipped descriptor a three-way comparison reads.
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
 * What the app shipped, what the instance runs and what the app now ships, whole or flattened to parts.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class DescriptorRevisions {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $baseline The definition the app shipped.
	 * @param array<string, mixed> $live     The definition the instance runs.
	 * @param array<string, mixed> $incoming The definition the app now ships.
	 */
	public function __construct(
		public readonly array $baseline,
		public readonly array $live,
		public readonly array $incoming,
	) {
	}//end __construct()
}//end class
