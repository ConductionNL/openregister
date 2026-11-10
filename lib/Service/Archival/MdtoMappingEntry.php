<?php

/**
 * OpenRegister MdtoMappingEntry
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * One entry of an MDTO mapping: the element and what was written against it.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class MdtoMappingEntry {
	/**
	 * Constructor.
	 *
	 * @param string $element The MDTO element name.
	 * @param mixed  $value   Whatever was written against it.
	 */
	public function __construct(
		public readonly string $element,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
