<?php

/**
 * SyncExecution: one harvest run of one source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The source and execution id that new sync records belong to.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SyncExecution {

	/**
	 * Constructor.
	 *
	 * @param Source $source      The harvested source.
	 * @param string $executionId The execution id.
	 */
	public function __construct(
		public readonly Source $source,
		public readonly string $executionId,
	) {
	}//end __construct()
}//end class
