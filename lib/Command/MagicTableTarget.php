<?php

/**
 * One magic table a command works on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A register, a schema of that register, and the table that holds their objects.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class MagicTableTarget {

	/**
	 * Constructor.
	 *
	 * @param Register $register The register
	 * @param Schema $schema The schema
	 * @param string $tableName The magic table
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly string $tableName,
	) {
	}//end __construct()

	/**
	 * The table as a console line names it.
	 *
	 * @return string Register, schema and table
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function label(): string {
		return sprintf(
			'%s/%s (%s)',
			$this->register->getSlug() ?? $this->register->getId(),
			$this->schema->getSlug() ?? $this->schema->getId(),
			$this->tableName
		);
	}//end label()
}//end class
