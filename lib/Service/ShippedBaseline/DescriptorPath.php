<?php

/**
 * Where a descriptor walk is: the dotted path so far and how deep it is.
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
 * A position in a descriptor, as {@see DescriptorParts::flatten()} walks it.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class DescriptorPath {

	/**
	 * Constructor.
	 *
	 * @param string $prefix The dotted path so far, '' at the root.
	 * @param int    $depth  How many maps deep the path is.
	 */
	public function __construct(
		public readonly string $prefix,
		public readonly int $depth,
	) {
	}//end __construct()
}//end class
