<?php

/**
 * The version a selectielijst import is stored under, and where it came from.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The named version written onto every imported row, and the source a row without its own `bron` is credited to.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ListVersion {
	/**
	 * Hold the values.
	 *
	 * @param string      $version The version, trimmed and non-empty.
	 * @param string|null $source  The source of the list, or null when none was given.
	 */
	public function __construct(
		public readonly string $version,
		public readonly ?string $source,
	) {
	}//end __construct()
}//end class
