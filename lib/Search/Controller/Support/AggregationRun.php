<?php

/**
 * One aggregation request being answered.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller\Support;

use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema references of one aggregation request, the schema
 * when it is already resolved, and the filter once it is read.
 *
 * Built by AggregationController for one request and never handed outside it.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class AggregationRun {
	/**
	 * The normalised filter map, once the request's filter is read.
	 *
	 * @var array<string, mixed>
	 */
	public array $filter = [];

	/**
	 * Constructor.
	 *
	 * @param string      $register     Register reference from the URL.
	 * @param string      $schema       Schema reference from the URL.
	 * @param Schema|null $schemaEntity The schema, when the action resolved it already.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $schema,
		public readonly ?Schema $schemaEntity = null,
	) {
	}//end __construct()
}//end class
