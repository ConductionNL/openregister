<?php

/**
 * A selectielijst row to store, new or over an existing one.
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
 * The row's data, and the uuid of the row it replaces, null for a new one.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class RowWrite {
	/**
	 * Hold the values.
	 *
	 * @param array       $data The row's data.
	 * @param string|null $uuid The uuid of the stored row it replaces, or null for a new row.
	 */
	public function __construct(
		public readonly array $data,
		public readonly ?string $uuid,
	) {
	}//end __construct()
}//end class
