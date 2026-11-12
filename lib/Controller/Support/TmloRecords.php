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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\TmloServiceInterface;

/**
 * Services of the tmlo API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
 */
final class TmloRecords {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper
	 * @param TmloServiceInterface    $tmloService    TMLO metadata service
	 * @param ObjectOperationsInterface  $objectService  Object service for querying objects
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-tmlo-metadata/tasks.md#task-1
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly TmloServiceInterface $tmloService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
