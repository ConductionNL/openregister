<?php

/**
 * OpenRegister table foreign key
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

use Doctrine\DBAL\Schema\ForeignKeyConstraint;


/**
 * A foreign-key constraint of a database source together with the table that
 * owns it — the constraint alone does not reliably name its local table.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class TableForeignKey {
	/**
	 * Pair the constraint with its table.
	 *
	 * @param string               $tableName  The owning table.
	 * @param ForeignKeyConstraint $constraint The constraint.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(
		public readonly string $tableName,
		public readonly ForeignKeyConstraint $constraint,
	) {
	}//end __construct()
}//end class
