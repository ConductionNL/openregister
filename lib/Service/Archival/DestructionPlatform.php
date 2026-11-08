<?php

/**
 * OpenRegister destruction platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;

/**
 * The object store, the configuration and the job list a destruction run works against.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class DestructionPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface $objectMapper Object entity data mapper.
	 * @param IAppConfig           $appConfig    App configuration.
	 * @param IJobList             $jobList      Background job list.
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly IAppConfig $appConfig,
		public readonly IJobList $jobList,
	) {
	}//end __construct()
}//end class
