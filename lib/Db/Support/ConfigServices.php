<?php

/**
 * ConfigServices: the app and system configuration readers of a mapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Support;

use OCP\IAppConfig;
use OCP\IConfig;

/**
 * The two configuration services: app values and system values.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisation-and-multitenancy-configuration-api
 */
final readonly class ConfigServices {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig $appConfig App configuration (app values such as the default organisation).
	 * @param IConfig    $config    System configuration (system values such as the table prefix).
	 */
	public function __construct(
		public IAppConfig $appConfig,
		public IConfig $config,
	) {
	}//end __construct()
}//end class
