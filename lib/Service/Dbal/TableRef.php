<?php

/**
 * OpenRegister table reference
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;


/**
 * A table or view of a database source, by name, and whether it is a view
 * (a view never has a primary key and is listed without one).
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class TableRef {
	/**
	 * Name the table or view.
	 *
	 * @param string $name   The table/view name.
	 * @param bool   $isView Whether this is a view.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $isView,
	) {
	}//end __construct()
}//end class
