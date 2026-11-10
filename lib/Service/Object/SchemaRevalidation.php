<?php

/**
 * OpenRegister SchemaRevalidation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;

/**
 * One validate-and-save pass over a register and schema's objects: which table, which page, and how to save.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SchemaRevalidation {

	/**
	 * Constructor.
	 *
	 * @param RegisterSchemaRef $table        The register and schema ids.
	 * @param ListQuery         $query        The page: its limit (or unbounded) and offset.
	 * @param array             $saveCallback Array-callable that saves objects, e.g. [$objectService, 'saveObject'].
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly RegisterSchemaRef $table,
		public readonly ListQuery $query,
		public readonly array $saveCallback,
	) {
	}//end __construct()
}//end class
