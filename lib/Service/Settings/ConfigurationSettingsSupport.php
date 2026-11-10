<?php

/**
 * OpenRegister configuration settings support services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
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

namespace OCA\OpenRegister\Service\Settings;

use OCA\OpenRegister\Service\Audit\SecuritySettingAnnouncer;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * The logger, the app manager and the audit hand-off of the configuration settings handler.
 *
 * @spec openspec/specs/settings-management/spec.md
 */
final class ConfigurationSettingsSupport {
	/**
	 * Bundle the support services.
	 *
	 * @param LoggerInterface                $logger         Logger.
	 * @param IAppManager                    $appManager     App manager, read for the app's own version info.
	 * @param SecuritySettingAnnouncer|null  $announcer      Tells the administrators when a marked setting moves.
	 * @param OwnSettingsChangeRecorder|null $changeRecorder Records every save on the audit trail, and announces it.
	 *
	 * @spec openspec/specs/settings-management/spec.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly IAppManager $appManager,
		public readonly ?SecuritySettingAnnouncer $announcer = null,
		public readonly ?OwnSettingsChangeRecorder $changeRecorder = null,
	) {
	}//end __construct()
}//end class
