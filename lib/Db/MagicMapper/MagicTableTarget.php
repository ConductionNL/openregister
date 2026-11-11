<?php

/**
 * MagicTableTarget: the register+schema a magic table operation works on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register, schema, physical table name and cache key of one table operation.
 *
 * MagicTableHandler fills the table name and cache key as it resolves them, so
 * its steps share one argument instead of four.
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */
final class MagicTableTarget {

	/**
	 * The physical table name, once resolved.
	 *
	 * @var string
	 */
	public string $tableName = '';

	/**
	 * The per-register+schema cache key, once resolved.
	 *
	 * @var string
	 */
	public string $cacheKey = '';

	/**
	 * Constructor.
	 *
	 * @param Register $register The register context.
	 * @param Schema   $schema   The schema context.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * The log context naming this target.
	 *
	 * @param string $file The file the caller logs from.
	 * @param int    $line The line the caller logs from.
	 *
	 * @return array<string, mixed> file, line, registerId, schemaId and tableName.
	 *
	 * @spec openspec/specs/magic-table-columns/spec.md
	 */
	public function logContext(string $file, int $line): array {
		return [
			'file' => $file,
			'line' => $line,
			'registerId' => $this->register->getId(),
			'schemaId' => $this->schema->getId(),
			'tableName' => $this->tableName,
		];
	}//end logContext()
}//end class
