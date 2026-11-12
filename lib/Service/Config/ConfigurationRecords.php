<?php

/**
 * OpenRegister configuration records
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
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

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;

/**
 * The stored configurations and the service that imports and exports them.
 *
 * Injected as one constructor argument by the classes that read configuration
 * rows and (re)import or export their content: the configurations API, the
 * configuration sync job and the configuration-set shareable type.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationRecords {
	/**
	 * Bundle the configuration mapper and service.
	 *
	 * @param ConfigurationMapperInterface  $configurationMapper  The stored configurations.
	 * @param ConfigurationServiceInterface $configurationService Import and export of their content.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ConfigurationMapperInterface $configurationMapper,
		public readonly ConfigurationServiceInterface $configurationService,
	) {
	}//end __construct()
}//end class
