<?php

/**
 * The table a split leaves and the definitions that decide its columns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
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

namespace OCA\OpenRegister\Service\SharedSchema;

/**
 * What {@see SchemaTableMigrator::planUnmapped()} compares: the bare source table, the
 * register's configured definition (null when the split clones) and the shared entity content.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SplitTableColumns {

	/**
	 * Constructor.
	 *
	 * @param string                    $table      The bare source table name.
	 * @param array<string, mixed>|null $definition The register's configured definition, or null when the split
	 *                                              falls back to cloning.
	 * @param array<string, mixed>      $content    The current shared entity content.
	 */
	public function __construct(
		public readonly string $table,
		public readonly ?array $definition,
		public readonly array $content,
	) {
	}//end __construct()
}//end class
