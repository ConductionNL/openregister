<?php

/**
 * OpenRegister ReferencingSchema
 *
 * A schema whose records can point at the entries of a destruction list.
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\Schema;

/**
 * A schema whose records can point at the entries of a destruction list, and
 * the relation fields they point through.
 *
 * @spec openspec/changes/retention-linked-destruction-conflict/specs/archival-destruction-workflow/spec.md#requirement-a-destruction-list-names-the-linked-records-its-entries-clash-with
 */
final class ReferencingSchema {
	/**
	 * Constructor.
	 *
	 * @param int                $registerId           The register holding the schema's table.
	 * @param Schema             $schema               The referencing schema.
	 * @param string             $fieldName            The relation field that points at the entries' schema.
	 * @param array<int, string> $additionalFieldNames Further relation fields that can point at it.
	 */
	public function __construct(
		public readonly int $registerId,
		public readonly Schema $schema,
		public readonly string $fieldName,
		public readonly array $additionalFieldNames=[],
	) {
	}//end __construct()
}//end class
