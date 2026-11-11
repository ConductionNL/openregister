<?php

/**
 * The services the configuration check job works with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\NotificationServiceInterface;

/**
 * Where the configurations are, the remote version check and import, and the update notification.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class ConfigurationCheckServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConfigurationMapperInterface  $configurationMapper  Where the configurations are.
	 * @param ConfigurationServiceInterface $configurationService Checks remote versions and imports updates.
	 * @param NotificationServiceInterface  $notificationService  Tells admins an update is available.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function __construct(
		public ConfigurationMapperInterface $configurationMapper,
		public ConfigurationServiceInterface $configurationService,
		public NotificationServiceInterface $notificationService,
	) {
	}//end __construct()
}//end class
