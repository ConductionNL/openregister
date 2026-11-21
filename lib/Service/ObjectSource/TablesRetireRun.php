<?php

/**
 * One retirement pass over the managed Tables schemas of the `tables` register.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;

/**
 * The register a retirement pass works on and which tables it retires; built and used only by TablesSchemaSyncService.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final class TablesRetireRun {
	/**
	 * Constructor.
	 *
	 * @param Register        $register     The `tables` register.
	 * @param array<int, int> $keepTableIds Table ids to keep (empty when only retiring one).
	 * @param int|null        $onlyTableId  When set, retire only this table's schema.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Register $register,
		public readonly array $keepTableIds,
		public readonly ?int $onlyTableId = null,
	) {
	}//end __construct()
}//end class
