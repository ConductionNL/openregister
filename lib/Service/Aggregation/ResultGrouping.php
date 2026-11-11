<?php

/**
 * OpenRegister ResultGrouping
 *
 * The table an aggregation result was read from and the group spec it is
 * grouped by.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\RegisterSchema;

/**
 * How an aggregation result is grouped: its register and schema, and the group spec.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class ResultGrouping {

	/**
	 * Constructor.
	 *
	 * @param RegisterSchema            $table   The register and schema the result was read from.
	 * @param array<string, mixed>|null $groupBy The group spec, in any accepted spelling
	 *                                           ({field}, {fields: [...]}, or a plain list).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly RegisterSchema $table,
		public readonly ?array $groupBy,
	) {
	}//end __construct()
}//end class
