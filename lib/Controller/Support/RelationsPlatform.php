<?php

/**
 * OpenRegister relations platform
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the relations API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
 */
final class RelationsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface    $logger    PSR-3 logger
	 * @param ContainerInterface $container App container the leaf link services are resolved from
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-data-integrity-relations/tasks.md#task-1
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly ContainerInterface $container,
	) {
	}//end __construct()
}//end class
