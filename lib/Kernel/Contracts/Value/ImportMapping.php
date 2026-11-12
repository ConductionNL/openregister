<?php

/**
 * OpenRegister ImportMapping
 *
 * How imported rows are mapped, and whether they are saved at all.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * How imported rows are mapped, and whether they are saved (ADR-011 Rule 2).
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */
final class ImportMapping {
	/**
	 * Build the mapping.
	 *
	 * @param array|null $pack   Optional migration pack definition (decoded JSON).
	 * @param bool       $dryRun When true, rows are mapped and validated but nothing is saved.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md
	 */
	public function __construct(
		public readonly ?array $pack = null,
		public readonly bool $dryRun = false,
	) {
	}//end __construct()
}//end class
