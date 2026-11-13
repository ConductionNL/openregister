<?php

/**
 * OpenRegister file settings controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Files\Controller\Support
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\File\DocumentStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The services the file settings controller uses.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
 */
final class FileSettingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ContainerInterface   $container       DI container, for the optional extraction backends.
	 * @param AppSettingsInterface $settingsService Settings service.
	 * @param LoggerInterface      $logger          Logger.
	 * @param DocumentStore|null   $documentStore   Where the documents live; null leaves it out of the file settings.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ContainerInterface $container,
		public readonly AppSettingsInterface $settingsService,
		public readonly LoggerInterface $logger,
		public readonly ?DocumentStore $documentStore = null,
	) {
	}//end __construct()
}//end class
