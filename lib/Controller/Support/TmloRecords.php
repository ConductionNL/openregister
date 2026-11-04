<?php

/**
 * OpenRegister tmlo records
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\TmloService;

/**
 * Services of the tmlo API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
 */
final class TmloRecords {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapper $registerMapper Register mapper
	 * @param SchemaMapper   $schemaMapper   Schema mapper
	 * @param TmloService    $tmloService    TMLO metadata service
	 * @param ObjectService  $objectService  Object service for querying objects
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
	 */
	public function __construct(
		public readonly RegisterMapper $registerMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly TmloService $tmloService,
		public readonly ObjectService $objectService,
	) {
	}//end __construct()
}//end class
