<?php

/**
 * OpenRegister sources platform
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

use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;

/**
 * The platform services of the sources API.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SourcesPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig $config The app configuration object
	 * @param IL10N      $l10n   The localization service
	 * @param ICrypto    $crypto Crypto service for databaseUrl encryption
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function __construct(
		public readonly IAppConfig $config,
		public readonly IL10N $l10n,
		public readonly ICrypto $crypto,
	) {
	}//end __construct()
}//end class
