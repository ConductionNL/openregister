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

use OCA\OpenRegister\Service\Config\ConfigurationRecords;
use OCA\OpenRegister\Kernel\Contracts\UploadServiceInterface;

/**
 * The services of the configurations API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConfigurationRecords          $records              The configuration mapper and service
	 * @param UploadServiceInterface        $uploadService        The upload service instance
	 * @param ControllerSession             $session              The session user and admin check
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ConfigurationRecords $records,
		public readonly UploadServiceInterface $uploadService,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
