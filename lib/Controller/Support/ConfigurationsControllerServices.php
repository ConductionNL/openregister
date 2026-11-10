<?php

/**
 * OpenRegister configurations controller services
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

use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\UploadServiceInterface;
use OCP\IUserSession;

/**
 * The services of the configurations API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConfigurationMapperInterface  $configurationMapper  The configuration mapper instance
	 * @param ConfigurationServiceInterface $configurationService The configuration service instance
	 * @param UploadServiceInterface        $uploadService        The upload service instance
	 * @param IUserSession         $userSession          User session for admin checks
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ConfigurationMapperInterface $configurationMapper,
		public readonly ConfigurationServiceInterface $configurationService,
		public readonly UploadServiceInterface $uploadService,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
