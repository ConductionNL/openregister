<?php

/**
 * The stores and queue a destruction list is processed against.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;

/**
 * Where destruction reads objects and settings, and where it queues the execution job.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class DestructionInfrastructure {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapperInterface $objectMapper Object entity data mapper.
	 * @param IAppConfig           $appConfig    App configuration.
	 * @param IJobList             $jobList      Background job list.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly IAppConfig $appConfig,
		public readonly IJobList $jobList,
	) {
	}//end __construct()
}//end class
