<?php

/**
 * OpenRegister ObjectService runtime services: logging, settings and the app container
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\AppFramework\IAppContainer;
use Psr\Log\LoggerInterface;

/**
 * ObjectService runtime services: logging, settings and the app container.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectRuntimeServices {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface          $logger          Logger for performance monitoring.
	 * @param SettingsServiceInterface $settingsService Service for settings operations.
	 * @param IAppContainer            $container       Application container.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly SettingsServiceInterface $settingsService,
		public readonly IAppContainer $container,
	) {
	}//end __construct()
}//end class
