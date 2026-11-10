<?php

/**
 * OpenRegister MatchQuery
 *
 * One row's match lookup: the mapped object, the declared key and the table it is matched in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\RegisterSchema;

/**
 * The query MatchResolver::matchesOf() answers (ADR-011 Rule 2).
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class MatchQuery {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object   The mapped object, in the shape the save path takes.
	 * @param array<int, string>   $matchKey The declared key's property names.
	 * @param RegisterSchema       $table    The target register and schema.
	 */
	public function __construct(
		public readonly array $object,
		public readonly array $matchKey,
		public readonly RegisterSchema $table,
	) {
	}//end __construct()
}//end class
