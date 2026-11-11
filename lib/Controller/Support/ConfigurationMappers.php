<?php

/**
 * OpenRegister configuration mappers
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

use OCA\OpenRegister\Db\ConfigurationMapperInterface;

/**
 * The mappers and repositories of the configuration API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationMappers {
	/**
	 * Bundle the services.
	 *
	 * @param ConfigurationMapperInterface $configurationMapper Configuration mapper
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ConfigurationMapperInterface $configurationMapper,
	) {
	}//end __construct()
}//end class
