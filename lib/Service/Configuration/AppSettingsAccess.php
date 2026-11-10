<?php

/**
 * AppSettingsAccess: reads and writes app settings and asks which apps are enabled.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The app-level settings store, the installed-app lookup, and the log a setting decision is reported to.
 *
 * Used by the classes whose behaviour depends on stored app settings and on
 * which optional apps are enabled (the credential-store resolver, the Doriath
 * application registrar and the e-Depot transfer preconditions).
 *
 * @spec openspec/specs/credential-broker/spec.md
 */
final class AppSettingsAccess {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig  The app settings store.
	 * @param IAppManager     $appManager Answers which apps are enabled.
	 * @param LoggerInterface $logger     Records a setting that could not be used.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly IAppManager $appManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
