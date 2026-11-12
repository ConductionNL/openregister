<?php

/**
 * How an object source reaches the optional Nextcloud app behind it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Whether the app behind a source is installed, its services, and where a failed read is logged.
 *
 * @spec openspec/changes/object-source-providers/tasks.md#1-provider-interface-registry
 */
final readonly class OptionalAppBridge {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IAppManager        $appManager App availability checks.
	 * @param ContainerInterface $container  Server container, for the lazy lookup of the app's services.
	 * @param LoggerInterface    $logger     Logger for read failures.
	 */
	public function __construct(
		public IAppManager $appManager,
		public ContainerInterface $container,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
