<?php

/**
 * OpenRegister schema platform services
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

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\UploadServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Uploads, organisations, logging and the DI container for the schemas API.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param UploadServiceInterface       $uploadService       The upload service.
	 * @param OrganisationServiceInterface $organisationService The organisation service.
	 * @param LoggerInterface     $logger              The logger.
	 * @param ContainerInterface  $container           The DI container.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly UploadServiceInterface $uploadService,
		public readonly OrganisationServiceInterface $organisationService,
		public readonly LoggerInterface $logger,
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class
